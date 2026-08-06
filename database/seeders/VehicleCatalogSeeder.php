<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehicleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'Abarth' => ['500', '595', '695'],
            'Alfa Romeo' => ['147', '156', '159', 'Giulia', 'Giulietta', 'Stelvio', 'Tonale'],
            'Alpine' => ['A110'],
            'Aston Martin' => ['DBX', 'DB11', 'Vantage'],
            'Audi' => ['A1', 'A3', 'A4', 'A5', 'A6', 'A7', 'A8', 'Q2', 'Q3', 'Q4 e-tron', 'Q5', 'Q7', 'Q8', 'e-tron'],
            'Bentley' => ['Bentayga', 'Continental GT', 'Flying Spur'],
            'BMW' => ['Serija 1', 'Serija 2', 'Serija 3', 'Serija 4', 'Serija 5', 'Serija 7', 'X1', 'X2', 'X3', 'X4', 'X5', 'X6', 'X7', 'i3', 'i4', 'iX'],
            'BYD' => ['Atto 3', 'Dolphin', 'Han', 'Seal', 'Tang'],
            'Chevrolet' => ['Aveo', 'Camaro', 'Captiva', 'Cruze', 'Orlando', 'Spark', 'Trax'],
            'Chrysler' => ['300C', 'Pacifica', 'Voyager'],
            'Citroën' => ['C1', 'C3', 'C3 Aircross', 'C4', 'C4 Cactus', 'C5 Aircross', 'Berlingo', 'Jumper'],
            'Cupra' => ['Ateca', 'Born', 'Formentor', 'Leon', 'Tavascan'],
            'Dacia' => ['Duster', 'Jogger', 'Logan', 'Sandero', 'Spring'],
            'Daewoo' => ['Kalos', 'Lanos', 'Matiz', 'Nubira'],
            'Daihatsu' => ['Cuore', 'Materia', 'Sirion', 'Terios'],
            'Dodge' => ['Challenger', 'Charger', 'Durango', 'Journey', 'RAM'],
            'DS Automobiles' => ['DS 3', 'DS 4', 'DS 7', 'DS 9'],
            'Ferrari' => ['296', 'Purosangue', 'Roma', 'SF90'],
            'Fiat' => ['500', '500L', '500X', 'Bravo', 'Doblo', 'Ducato', 'Grande Punto', 'Panda', 'Punto', 'Tipo'],
            'Ford' => ['B-Max', 'C-Max', 'EcoSport', 'Edge', 'Explorer', 'Fiesta', 'Focus', 'Kuga', 'Mondeo', 'Mustang', 'Puma', 'Ranger', 'Transit'],
            'Genesis' => ['G70', 'G80', 'GV60', 'GV70', 'GV80'],
            'Honda' => ['Accord', 'Civic', 'CR-V', 'e:Ny1', 'HR-V', 'Jazz'],
            'Hyundai' => ['Bayon', 'Getz', 'i10', 'i20', 'i30', 'Ioniq 5', 'Ioniq 6', 'Kona', 'Santa Fe', 'Tucson'],
            'Infiniti' => ['FX', 'Q30', 'Q50', 'QX30', 'QX70'],
            'Isuzu' => ['D-Max', 'MU-X'],
            'Iveco' => ['Daily'],
            'Jaguar' => ['E-Pace', 'F-Pace', 'F-Type', 'I-Pace', 'XE', 'XF'],
            'Jeep' => ['Avenger', 'Cherokee', 'Compass', 'Grand Cherokee', 'Renegade', 'Wrangler'],
            'Kia' => ['Ceed', 'EV3', 'EV6', 'Niro', 'Picanto', 'ProCeed', 'Rio', 'Sorento', 'Sportage', 'Stonic', 'XCeed'],
            'Lada' => ['Niva', 'Samara', 'Vesta'],
            'Lamborghini' => ['Huracán', 'Revuelto', 'Urus'],
            'Lancia' => ['Delta', 'Ypsilon'],
            'Land Rover' => ['Defender', 'Discovery', 'Discovery Sport', 'Range Rover', 'Range Rover Evoque', 'Range Rover Sport', 'Range Rover Velar'],
            'Lexus' => ['ES', 'GS', 'IS', 'LBX', 'NX', 'RX', 'UX'],
            'Lotus' => ['Eletre', 'Emira', 'Evija'],
            'Maserati' => ['Ghibli', 'Grecale', 'Levante', 'MC20', 'Quattroporte'],
            'Mazda' => ['2', '3', '6', 'CX-3', 'CX-30', 'CX-5', 'CX-60', 'MX-5'],
            'Mercedes-Benz' => ['A-klasa', 'B-klasa', 'C-klasa', 'E-klasa', 'S-klasa', 'CLA', 'CLS', 'EQA', 'EQB', 'EQE', 'EQS', 'GLA', 'GLB', 'GLC', 'GLE', 'GLS', 'Vito'],
            'MG' => ['HS', 'MG3', 'MG4', 'MG5', 'ZS'],
            'MINI' => ['Clubman', 'Cooper', 'Countryman'],
            'Mitsubishi' => ['ASX', 'Colt', 'Eclipse Cross', 'L200', 'Outlander', 'Pajero', 'Space Star'],
            'Nissan' => ['Ariya', 'Juke', 'Leaf', 'Micra', 'Navara', 'Note', 'Pathfinder', 'Qashqai', 'X-Trail'],
            'Opel' => ['Adam', 'Antara', 'Astra', 'Combo', 'Corsa', 'Crossland', 'Grandland', 'Insignia', 'Meriva', 'Mokka', 'Vectra', 'Zafira'],
            'Peugeot' => ['107', '108', '2008', '206', '207', '208', '3008', '307', '308', '4008', '5008', '508', 'Partner', 'Rifter'],
            'Polestar' => ['2', '3', '4'],
            'Porsche' => ['718', '911', 'Cayenne', 'Macan', 'Panamera', 'Taycan'],
            'Renault' => ['Austral', 'Captur', 'Clio', 'Espace', 'Kadjar', 'Kangoo', 'Laguna', 'Master', 'Megane', 'Rafale', 'Scenic', 'Twingo'],
            'Rolls-Royce' => ['Cullinan', 'Ghost', 'Phantom', 'Spectre'],
            'Rover' => ['25', '45', '75'],
            'Saab' => ['9-3', '9-5'],
            'SEAT' => ['Alhambra', 'Arona', 'Ateca', 'Ibiza', 'Leon', 'Tarraco', 'Toledo'],
            'Škoda' => ['Citigo', 'Enyaq', 'Fabia', 'Kamiq', 'Karoq', 'Kodiaq', 'Octavia', 'Rapid', 'Scala', 'Superb', 'Yeti'],
            'Smart' => ['ForFour', 'ForTwo', '#1', '#3'],
            'SsangYong' => ['Korando', 'Musso', 'Rexton', 'Tivoli', 'Torres'],
            'Subaru' => ['BRZ', 'Crosstrek', 'Forester', 'Impreza', 'Legacy', 'Outback', 'XV'],
            'Suzuki' => ['Across', 'Ignis', 'Jimny', 'S-Cross', 'Swift', 'Vitara'],
            'Tesla' => ['Model 3', 'Model S', 'Model X', 'Model Y'],
            'Toyota' => ['Auris', 'Avensis', 'Aygo', 'C-HR', 'Camry', 'Corolla', 'Highlander', 'Hilux', 'Land Cruiser', 'Prius', 'Proace', 'RAV4', 'Yaris', 'Yaris Cross'],
            'Volkswagen' => ['Amarok', 'Arteon', 'Caddy', 'Golf', 'ID.3', 'ID.4', 'ID.5', 'Passat', 'Polo', 'Sharan', 'T-Cross', 'T-Roc', 'Tiguan', 'Touareg', 'Touran', 'Transporter'],
            'Volvo' => ['C40', 'EX30', 'EX40', 'S60', 'S90', 'V40', 'V60', 'V90', 'XC40', 'XC60', 'XC90'],
        ];

        foreach ($catalog as $brandName => $models) {
            DB::table('vehicle_brands')->updateOrInsert(
                ['name' => $brandName],
                ['updated_at' => now(), 'created_at' => now()],
            );
            $id = DB::table('vehicle_brands')->where('name', $brandName)->value('id');
            foreach ($models as $model) {
                DB::table('vehicle_models')->updateOrInsert(
                    ['vehicle_brand_id' => $id, 'name' => $model],
                    ['updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }
}
