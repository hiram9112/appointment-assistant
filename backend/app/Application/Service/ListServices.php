<?php

namespace App\Application\Service;

use App\Models\Business;
use App\Models\Service;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ListServices
{
    /** @return list<ServiceInformation> */
    public function handle(Business $business): array
    {
        return $business->services()
            ->where('active', true)
            ->with(['professionals' => fn (BelongsToMany $query) => $query
                ->where('professionals.business_id', $business->id)
                ->orderBy('first_name')->orderBy('last_name')->orderBy('professionals.id')])
            ->orderBy('name')
            ->orderBy('services.id')
            ->get()
            ->map(fn (Service $service): ServiceInformation => ServiceInformation::fromService($service))
            ->all();
    }
}
