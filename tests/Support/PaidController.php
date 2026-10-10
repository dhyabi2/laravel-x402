<?php

declare(strict_types=1);

namespace Requestway\X402\Tests\Support;

use Illuminate\Routing\Controller;
use Requestway\X402\Attributes\RequiresPayment;

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
