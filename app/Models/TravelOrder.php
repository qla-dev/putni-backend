<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'order_number',
        'status',
        'employee_name',
        'employee_title',
        'employee_oib',
        'employee_iban',
        'company_name',
        'company_oib',
        'route',
        'start_location',
        'destination_country',
        'purpose',
        'departure_time',
        'arrival_time',
        'route_stop_times',
        'total_hours',
        'transport_type',
        'vehicle_name',
        'vehicle_plate',
        'total_km',
        'total_km_cost',
        'bmb95_price',
        'daily_allowance_rate_eur',
        'total_allowance_cost',
        'breakfast_included',
        'lunch_included',
        'dinner_included',
        'hotel_included',
        'residence_distance_km',
        'expenses',
        'total_expenses_cost',
        'advancement_paid',
        'grand_total',
        'balance_to_pay',
        'notes',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'departure_time' => 'datetime',
            'arrival_time' => 'datetime',
            'route_stop_times' => 'array',
            'expenses' => 'array',
            'total_hours' => 'float',
            'total_km' => 'float',
            'total_km_cost' => 'float',
            'bmb95_price' => 'float',
            'daily_allowance_rate_eur' => 'float',
            'total_allowance_cost' => 'float',
            'breakfast_included' => 'boolean',
            'lunch_included' => 'boolean',
            'dinner_included' => 'boolean',
            'hotel_included' => 'boolean',
            'residence_distance_km' => 'float',
            'total_expenses_cost' => 'float',
            'advancement_paid' => 'float',
            'grand_total' => 'float',
            'balance_to_pay' => 'float',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getRouteKeyName(): string
    {
        return 'client_id';
    }
}
