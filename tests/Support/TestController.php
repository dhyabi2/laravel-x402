<?php

declare(strict_types=1);

namespace Requestway\X402\Tests\Support;

use Illuminate\Routing\Controller;
use Requestway\X402\Attributes\RequiresPayment;

class TestController extends Controller
{
    #[RequiresPayment(price: '0.02', description: 'Attribute priced', mimeType: 'text/csv')]
    public function show(): array
    {
        return ['data' => 'from attribute'];
    }

    public function free(): array
    {
        return ['data' => 'free'];
    }

    #[RequiresPayment(price: 'premium', tags: ['reports'])]
    public function tiered(): array
    {
        return ['data' => 'tiered'];
    }
}
