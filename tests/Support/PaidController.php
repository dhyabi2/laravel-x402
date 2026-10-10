<?php

declare(strict_types=1);

namespace HydraLabs\X402\Tests\Support;

use HydraLabs\X402\Attributes\RequiresPayment;
use Illuminate\Routing\Controller;

/**
 * Every action is paid, with one overriding the class-level price.
 */
#[RequiresPayment(price: '0.05', description: 'Class default')]
class PaidController extends Controller
{
    public function index(): array
    {
        return ['data' => 'class priced'];
    }

    #[RequiresPayment(price: '0.50')]
    public function expensive(): array
    {
        return ['data' => 'method priced'];
    }
}
