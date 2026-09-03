<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    /**
     * Barangays of Tangub City, Misamis Occidental.
     */
    public const BARANGAYS = [
        'Barangay I - City Hall (Poblacion)',
        'Barangay II - Marilou Annex (Poblacion)',
        'Barangay III - Market Kalubian (Poblacion)',
        'Barangay IV - St. Michael (Poblacion)',
        'Barangay V - Malubog (Poblacion)',
        'Barangay VI - Lower Polao (Poblacion)',
        'Barangay VII - Upper Polao (Poblacion)',
        'Aquino',
        'Balatacan',
        'Baluc',
        'Banglay',
        'Bintana',
        'Bocator',
        'Bongabong',
        'Caniangan',
        'Capalaran',
        'Catagan',
        'Hoyohoy',
        'Isidro D. Tan (Dimalooc)',
        'Garang',
        'Guinabot',
        'Guinalaban',
        'Kausawagan',
        'Kimat',
        'Labuyo',
        'Lorenzo Tan',
        'Lumban',
        'Maloro',
        'Manga',
        'Mantic',
        'Maquilao',
        'Matugnao',
        'Migcanaway',
        'Minsubong',
        'Owayan',
        'Paiton',
        'Panalsalan',
        'Pangabuan',
        'Prenza',
        'Salimpuno',
        'San Antonio',
        'San Apolinario',
        'San Vicente',
        'Santa Cruz',
        'Santa Maria (Baga)',
        'Santo Niño',
        'Sicot',
        'Silanga',
        'Silangit',
        'Simasay',
        'Sumirap',
        'Taguite',
        'Tituron',
        'Tugas',
        'Villaba',
    ];

    public function run(): void
    {
        foreach (self::BARANGAYS as $name) {
            Barangay::updateOrCreate(['name' => $name]);
        }
    }
}
