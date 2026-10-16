<?php
namespace App\Providers;
use App\Services\Finance\{StripeGateway, StripeTestGateway};
use Illuminate\Support\ServiceProvider;
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(StripeGateway::class, StripeTestGateway::class); }
}
