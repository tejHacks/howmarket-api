<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\Market;
use App\Models\PriceReport;
use App\Models\ReportVote;
use App\Models\User;
use App\Services\Consensus;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Prices. Reading is public; reporting and voting need an account, and the server
 * enforces the rules (daily limit, one vote each, no voting on your own report,
 * trust only changed here).
 */
class PriceController extends Controller
{
    /**
     * Everything the app needs for one area: markets, items, the last 14 days of
     * reports with their votes, reporters' trust (by anonymous id) and, when signed
     * in, your own votes. The app computes consensus from this with the same code.
     */
    public function snapshot(Request $request): JsonResponse
    {
        $area = $request->validate(['area' => ['required', Rule::in(AuthController::AREAS)]])['area'];
        $viewer = auth('sanctum')->user();

        $marketIds = Market::where('area', $area)->pluck('id');
        $reports = $this->recentReports($marketIds);

        $myVotes = [];
        if ($viewer) {
            $myVotes = ReportVote::where('user_id', $viewer->id)
                ->whereIn('report_id', $reports->pluck('id'))
                ->get()
                ->mapWithKeys(fn ($v) => [(string) $v->report_id => $v->vote === 1 ? 'confirm' : 'flag'])
                ->all();
        }

        return response()->json([
            'area' => $area,
            'markets' => Market::orderBy('name')->get(['id', 'name', 'area']),
            'items' => Item::orderBy('name')->get(['id', 'name', 'unit', 'emoji']),
            'reports' => $reports->map(fn ($r) => [
                'id' => (string) $r->id,
                'itemId' => $r->item_id,
                'marketId' => $r->market_id,
                'userId' => $r->user->publicId(),
                'price' => $r->price,
                'createdAt' => $r->created_at->toIso8601ZuluString('millisecond'),
                'confirms' => (int) $r->confirms,
                'flags' => (int) $r->flags,
            ])->values(),
            'trust' => $reports->mapWithKeys(fn ($r) => [$r->user->publicId() => (float) $r->user->trust]),
            'myVotes' => (object) $myVotes,
            'me' => $viewer ? AuthController::profile($viewer) : null,
        ]);
    }

    /** The server's own consensus for one item, per market in an area and area-wide. */
    public function itemPrices(Request $request, string $itemId): JsonResponse
    {
        $area = $request->validate(['area' => ['required', Rule::in(AuthController::AREAS)]])['area'];
        $item = Item::findOrFail($itemId);
        $markets = Market::where('area', $area)->orderBy('name')->get();
        $reports = $this->recentReports($markets->pluck('id'), $item->id);
        $now = (int) (microtime(true) * 1000);

        $summarise = function (Collection $rs) use ($now) {
            $c = Consensus::of($this->forConsensus($rs), $this->trustOf($rs), $now);

            return $c ? collect($c)->except(['used', 'outliers'])->merge(['outlierCount' => count($c['outliers'])]) : null;
        };

        return response()->json([
            'item' => $item->only(['id', 'name', 'unit', 'emoji']),
            'area' => $area,
            'areaPrice' => $summarise($reports),
            'markets' => $markets->map(fn ($m) => [
                'market' => $m->only(['id', 'name']),
                'price' => $summarise($reports->where('market_id', $m->id)),
            ]),
        ]);
    }

    /** Report a price. 429 after 20 reports in 24 hours. Your trust updates here, never on the phone. */
    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'string', 'exists:items,id'],
            'market_id' => ['required', 'string', 'exists:markets,id'],
            'price' => ['required', 'integer', 'min:1', 'max:99999999'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return DB::transaction(function () use ($user, $data) {
            $user = User::lockForUpdate()->find($user->id);
            $today = $user->reports()->where('created_at', '>=', now()->subDay())->count();
            if ($today >= Consensus::DAILY_REPORT_LIMIT) {
                return response()->json(['message' => 'You’ve reached today’s limit of '.Consensus::DAILY_REPORT_LIMIT.' price reports. Try again tomorrow.'], 429);
            }

            // Score the reporter against the current consensus, before their own report counts.
            $existing = $this->recentReports(collect([$data['market_id']]), $data['item_id']);
            $before = Consensus::of($this->forConsensus($existing), $this->trustOf($existing), (int) (microtime(true) * 1000));
            if ($before && $before['reporters'] >= 2) {
                $user->trust = Consensus::nextTrust((float) $user->trust, $data['price'], $before['price']);
                $user->save();
            }

            $report = $user->reports()->create($data);

            return response()->json([
                'report' => ['id' => (string) $report->id, 'itemId' => $report->item_id, 'marketId' => $report->market_id, 'price' => $report->price],
                'me' => AuthController::profile($user->fresh()),
            ], 201);
        });
    }

    /** Confirm a price or flag it as wrong. Once per report, and never your own. */
    public function vote(Request $request, string $reportId): JsonResponse
    {
        $data = $request->validate(['vote' => ['required', Rule::in(['confirm', 'flag'])]]);
        $report = PriceReport::findOrFail($reportId);
        $user = $request->user();

        if ((int) $report->user_id === (int) $user->id) {
            return response()->json(['message' => 'You can’t vote on your own report.'], 403);
        }

        try {
            ReportVote::create(['report_id' => $report->id, 'user_id' => $user->id, 'vote' => $data['vote'] === 'confirm' ? 1 : -1]);
        } catch (QueryException) {
            return response()->json(['message' => 'You’ve already voted on this report.'], 409);
        }

        return response()->json(['ok' => true, 'vote' => $data['vote']], 201);
    }

    /** Reports from the last 14 days for some markets, with vote counts and reporters. */
    private function recentReports(Collection $marketIds, ?string $itemId = null): Collection
    {
        return PriceReport::query()
            ->with('user:id,trust')
            ->withCount([
                'votes as confirms' => fn ($q) => $q->where('vote', 1),
                'votes as flags' => fn ($q) => $q->where('vote', -1),
            ])
            ->whereIn('market_id', $marketIds)
            ->when($itemId, fn ($q) => $q->where('item_id', $itemId))
            ->where('created_at', '>=', now()->subDays(Consensus::WINDOW_DAYS))
            ->orderBy('created_at')
            ->get();
    }

    private function forConsensus(Collection $reports): array
    {
        return $reports->map(fn ($r) => [
            'userId' => (string) $r->user_id,
            'price' => $r->price,
            'createdAt' => (int) $r->created_at->getTimestampMs(),
            'confirms' => (int) $r->confirms,
            'flags' => (int) $r->flags,
        ])->values()->all();
    }

    private function trustOf(Collection $reports): callable
    {
        $trust = $reports->mapWithKeys(fn ($r) => [(string) $r->user_id => (float) $r->user->trust])->all();

        return fn (string $userId) => $trust[$userId] ?? 1.0;
    }
}
