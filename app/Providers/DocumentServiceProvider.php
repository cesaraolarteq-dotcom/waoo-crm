<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use App\Services\Documents\{SignatureProvider, InHouseSignatureProvider};
class DocumentServiceProvider extends ServiceProvider
{
    public function register(): void { $this->app->bind(SignatureProvider::class, InHouseSignatureProvider::class); }
}
