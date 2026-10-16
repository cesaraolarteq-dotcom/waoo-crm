<?php
namespace App\Services\Documents;

use App\Models\{Contract, SignatureEvidence};
use App\Services\Portal\PortalContext;
interface SignatureProvider
{
    public function capture(Contract $contract, PortalContext $client, array $input, SignatureRequestContext $request): SignatureEvidence;
}
