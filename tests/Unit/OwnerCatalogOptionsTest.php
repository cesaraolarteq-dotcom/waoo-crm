<?php
namespace Tests\Unit;
use App\Support\OwnerCatalogOptions;
use PHPUnit\Framework\TestCase;
class OwnerCatalogOptionsTest extends TestCase
{
    public function test_approved_option_preserves_two_hour_price_and_exact_recipe_and_is_repeatable(): void
    {
        $options = require __DIR__.'/../../config/owner-catalog-options.php';
        $source = ['name'=>'Glitter Mobile','duration_min'=>120,'price'=>475,'crew'=>['host'=>1],'gear'=>['vanity'=>1],'setup_min'=>15,'ready'=>true];
        $result = OwnerCatalogOptions::packages(['glitter'=>$source],$options);
        self::assertSame($source,$result['glitter']);
        self::assertSame(90,$result['glitter-owner-90min']['duration_min']);
        self::assertEquals(350,$result['glitter-owner-90min']['price']);
        self::assertSame($source['crew'],$result['glitter-owner-90min']['crew']);
        self::assertSame($source['gear'],$result['glitter-owner-90min']['gear']);
        self::assertSame($result,OwnerCatalogOptions::packages($result,$options));
        self::assertSame([],OwnerCatalogOptions::packages([],$options));
        self::assertSame(15,$options['baby_shower']['setup_minutes']);
        self::assertSame(15,$options['baby_shower']['breakdown_minutes']);
    }
}
