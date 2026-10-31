<?php

namespace App\Application\Service;

use App\Models\Business;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class GetServiceInformation
{
    public function handle(Business $business, int $serviceId): ?ServiceInformation
    {
        $service = $business->services()
            ->where('active', true)
            ->with(['professionals' => fn (BelongsToMany $query) => $query
                ->where('professionals.business_id', $business->id)
                ->orderBy('first_name')->orderBy('last_name')->orderBy('professionals.id')])
            ->find($serviceId);

        return $service === null ? null : ServiceInformation::fromService($service);
    }
}
