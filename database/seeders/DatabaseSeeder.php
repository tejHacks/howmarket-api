<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Market;
use Illuminate\Database\Seeder;

/** Real markets and goods. No prices: those only come from people. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['id' => 'bodija', 'name' => 'Bodija Market', 'area' => 'Ibadan'],
            ['id' => 'oja-oba', 'name' => 'Oja Oba', 'area' => 'Ibadan'],
            ['id' => 'dugbe', 'name' => 'Dugbe Market', 'area' => 'Ibadan'],
            ['id' => 'mile12', 'name' => 'Mile 12 Market', 'area' => 'Lagos'],
            ['id' => 'oyingbo', 'name' => 'Oyingbo Market', 'area' => 'Lagos'],
            ['id' => 'wuse', 'name' => 'Wuse Market', 'area' => 'Abuja'],
        ] as $m) {
            Market::updateOrCreate(['id' => $m['id']], $m);
        }

        foreach ([
            ['id' => 'rice-50kg', 'name' => 'Rice', 'unit' => '50kg bag', 'emoji' => '🍚'],
            ['id' => 'garri-paint', 'name' => 'Garri (white)', 'unit' => 'paint bucket', 'emoji' => '🥣'],
            ['id' => 'beans-paint', 'name' => 'Beans (oloyin)', 'unit' => 'paint bucket', 'emoji' => '🫘'],
            ['id' => 'tomato-basket', 'name' => 'Tomatoes', 'unit' => 'big basket', 'emoji' => '🍅'],
            ['id' => 'palm-oil-25l', 'name' => 'Palm oil', 'unit' => '25 litres', 'emoji' => '🛢️'],
            ['id' => 'yam-tuber', 'name' => 'Yam', 'unit' => 'medium tuber', 'emoji' => '🍠'],
            ['id' => 'eggs-crate', 'name' => 'Eggs', 'unit' => 'crate (30)', 'emoji' => '🥚'],
            ['id' => 'onions-bag', 'name' => 'Onions', 'unit' => 'small bag', 'emoji' => '🧅'],
        ] as $i) {
            Item::updateOrCreate(['id' => $i['id']], $i);
        }
    }
}
