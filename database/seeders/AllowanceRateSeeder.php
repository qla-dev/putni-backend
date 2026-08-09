<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AllowanceRateSeeder extends Seeder
{
    /** Seed the official daily-allowance schedule, in BAM. */
    public function run(): void
    {
        $rates = [
            'Albania' => 110, 'Algeria' => 100, 'Angola' => 140, 'Argentina' => 120,
            'Armenia' => 100, 'Australia' => 120, 'Austria' => 120, 'Afghanistan' => 100,
            'Bangladesh' => 110, 'Bahrain' => 110, 'Belgium' => 120, 'Benin' => 100,
            'Belarus' => 100, 'Botswana' => 90, 'Bolivia' => 100, 'Brazil' => 120,
            'Bulgaria' => 100, 'Burkina Faso' => 100, 'Burundi' => 100,
            'Central African Republic' => 110, 'Montenegro' => 100, 'Chad' => 130,
            'Czech Republic' => 110, 'Chile' => 110, 'Denmark' => 140,
            'Dominican Republic' => 110, 'Djibouti' => 110, 'Egypt' => 100,
            'Ecuador' => 100, 'Estonia' => 90, 'Ethiopia' => 110, 'Philippines' => 110,
            'Finland' => 120, 'France' => 120, 'Gabon' => 100, 'Ghana' => 120,
            'Greece' => 110, 'Guyana' => 100, 'Guatemala' => 100,
            'Papua New Guinea' => 100, 'Equatorial Guinea' => 120, 'Guinea' => 100,
            'Guinea-Bissau' => 120, 'Haiti' => 120, 'Honduras' => 100, 'Hong Kong' => 140,
            'Croatia' => 110, 'India' => 100, 'Indonesia' => 110, 'Iraq' => 100,
            'Iran' => 100, 'Ireland' => 130, 'Iceland' => 150, 'Italy' => 120,
            'Israel' => 130, 'Japan' => 150, 'Yemen' => 100, 'Jordan' => 110,
            'South Korea' => 130, 'Cambodia' => 90, 'Cameroon' => 110, 'Canada' => 120,
            'Qatar' => 110, 'Kenya' => 110, 'China' => 130, 'Cyprus' => 110,
            'Colombia' => 110, 'Republic of the Congo' => 130, 'Costa Rica' => 110,
            'Cuba' => 100, 'Kuwait' => 110, 'Laos' => 90, 'Lesotho' => 90,
            'Latvia' => 100, 'Lebanon' => 100, 'Liberia' => 100, 'Libya' => 100,
            'Lithuania' => 110, 'Luxembourg' => 130, 'Hungary' => 100,
            'Madagascar' => 100, 'North Macedonia' => 90, 'Malawi' => 100,
            'Malaysia' => 90, 'Mali' => 100, 'Malta' => 100, 'Morocco' => 100,
            'Mauritania' => 100, 'Mexico' => 100, 'Myanmar' => 100, 'Mongolia' => 110,
            'Mozambique' => 120, 'Namibia' => 90, 'Nepal' => 90, 'Niger' => 110,
            'Nigeria' => 120, 'Nicaragua' => 100, 'Netherlands' => 130, 'Norway' => 130,
            'New Zealand' => 130, 'Germany' => 120, "Côte d'Ivoire" => 130,
            'Oman' => 110, 'Pakistan' => 100, 'Panama' => 100, 'Paraguay' => 100,
            'Peru' => 100, 'Poland' => 100, 'Puerto Rico' => 90, 'Portugal' => 110,
            'Rwanda' => 110, 'Romania' => 100, 'Russia' => 120, 'United States' => 130,
            'El Salvador' => 100, 'São Tomé and Príncipe' => 100,
            'Saudi Arabia' => 110, 'Seychelles' => 130, 'Singapore' => 130,
            'Sierra Leone' => 100, 'Syria' => 100, 'North Korea' => 100,
            'Slovakia' => 110, 'Slovenia' => 110, 'Somalia' => 90, 'Serbia' => 100,
            'Sudan' => 110, 'Suriname' => 100, 'Spain' => 120, 'Sri Lanka' => 100,
            'Sweden' => 130, 'Switzerland' => 150, 'Thailand' => 110, 'Taiwan' => 130,
            'Tanzania' => 100, 'Togo' => 110, 'Trinidad and Tobago' => 110,
            'Tunisia' => 90, 'Turkey' => 110, 'United Arab Emirates' => 120,
            'Uganda' => 100, 'United Kingdom' => 140, 'Ukraine' => 110,
            'Uruguay' => 130, 'Venezuela' => 90, 'Vietnam' => 110,
            'Democratic Republic of the Congo' => 90, 'Zambia' => 100, 'Zimbabwe' => 100,
        ];

        $now = now();
        $rows = [
            ['country' => 'Bosnia and Herzegovina', 'rate_km' => 0, 'rate_bam' => 45.00, 'region' => 'BiH', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($rates as $country => $rate) {
            $rows[] = ['country' => $country, 'rate_km' => 0, 'rate_bam' => $rate, 'region' => 'International', 'is_default' => false, 'created_at' => $now, 'updated_at' => $now];
        }

        $rows[] = ['country' => 'All other countries', 'rate_km' => 0, 'rate_bam' => 90.00, 'region' => 'International', 'is_default' => true, 'created_at' => $now, 'updated_at' => $now];

        DB::transaction(function () use ($rows): void {
            DB::table('allowance_rates')->delete();
            DB::table('allowance_rates')->insert($rows);
        });
    }
}
