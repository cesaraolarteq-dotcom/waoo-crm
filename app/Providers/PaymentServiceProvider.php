<?php
namespace App\Providers;
use App\Services\Finance\{StripeGateway, StripeRefunds, StripeTestGateway, StripeTestRefunds};
use Illuminate\Support\ServiceProvider;
class PaymentServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(StripeGateway::class, StripeTestGateway::class); $this->app->bind(StripeRefunds::class, StripeTestRefunds::class); }
}
