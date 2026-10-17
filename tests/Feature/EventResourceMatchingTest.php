<?php
namespace Tests\Feature;
use App\Services\MatchEventResources;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
class EventResourceMatchingTest extends TestCase
{
    public function test_exhaustive_small_graph_oracle_for_coverage_and_maximum_drivers():void
    {
        $slots=[['index'=>0],['index'=>1],['index'=>2]];$solver=new MatchEventResources;
        // All 512 compatibility graphs × all 8 driver subsets. Independent brute-force permutations.
        foreach(range(0,511) as $edges)foreach(range(0,7) as $drivers){
            $resources=[];foreach(range(0,2) as $r)$resources[]=(object)['index'=>$r,'kind'=>'staff','skills'=>($drivers&(1<<$r))?['driver']:[]];
            $fits=fn($s,$r)=>(bool)($edges&(1<<($s['index']*3+$r->index)));$best=-1;
            foreach([[0,1,2],[0,2,1],[1,0,2],[1,2,0],[2,0,1],[2,1,0]] as $permutation){
                if($fits($slots[0],$resources[$permutation[0]])&&$fits($slots[1],$resources[$permutation[1]])&&$fits($slots[2],$resources[$permutation[2]]))$best=substr_count(decbin($drivers),'1');
            }
            try{$a=$solver->execute($slots,$resources,$fits);$this->assertGreaterThanOrEqual(0,$best);$this->assertCount(3,$a);foreach($a as $r=>$s)$this->assertTrue($fits($slots[$s],$resources[$r]));}
            catch(ValidationException){$this->assertSame(-1,$best);}
        }
        // Oversubscribed resources: some drivers are incompatible with roles; the optimal subset matters.
        foreach(range(0,511) as $edges)foreach(range(0,7) as $drivers){
            $resources=[];foreach(range(0,2) as $r)$resources[]=(object)['index'=>$r,'kind'=>'staff','skills'=>($drivers&(1<<$r))?['driver']:[]];
            $fits=fn($s,$r)=>(bool)($edges&(1<<($s['index']*3+$r->index)));$best=-1;
            foreach(range(0,2) as $a)foreach(range(0,2) as $b)if($a!==$b&&$fits($slots[0],$resources[$a])&&$fits($slots[1],$resources[$b]))$best=max($best,(int)MatchEventResources::driver($resources[$a])+(int)MatchEventResources::driver($resources[$b]));
            try{$a=$solver->execute(array_slice($slots,0,2),$resources,$fits);$this->assertGreaterThanOrEqual(0,$best);$this->assertSame($best,count(array_filter(array_keys($a),fn($i)=>MatchEventResources::driver($resources[$i]))));}
            catch(ValidationException){$this->assertSame(-1,$best);}
        }
    }
}
