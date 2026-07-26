<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExportFormatResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'format' => strtoupper($this->extension),
            'mimeType' => $this->mime_type,
            'icon' => $this->icon,
            'color' => $this->color,
            'isIntegration' => $this->is_integration,
        ];
    }
}
