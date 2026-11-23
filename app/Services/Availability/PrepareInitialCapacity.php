<?php
namespace App\Services\Availability;

use App\Models\{Event,EventService,EventServiceCharacter,PriceVersion,ServiceVariant,User};
use App\Services\Records\{CharacterRecipe,PackageTimingDefaults,RecordActor,RecordInput,SameLengthExtras};
use Carbon\CarbonImmutable;

/** An unsaved capacity-only candidate. Customer/event/audit records are created only in the final atomic transaction. */
final class PrepareInitialCapacity
{
    public function execute(User $actor,array $input): array
    {
        RecordActor::authorize($actor);
        $d=RecordInput::validate(\Illuminate\Support\Arr::only($input,['starts_at','ends_at','address','postal_code','setup_minutes','breakdown_minutes','services']),[
            'starts_at'=>['required','date',new \App\Rules\ExplicitOffset], 'ends_at'=>['required','date','after:starts_at',new \App\Rules\ExplicitOffset],
            'address'=>'required|string|max:255','postal_code'=>'required|string|regex:/^\d{5}(?:-\d{4})?$/D',
            ...PackageTimingDefaults::rules(),'services'=>'required|array|min:1|max:10','services.*.price_version_id'=>'required|integer|distinct|exists:price_versions,id',
            'services.*.characters'=>'sometimes|array|max:20','services.*.characters.*.character_id'=>'required|integer|exists:characters,id',
            'services.*.characters.*.quantity'=>'required|integer|min:1|max:20','services.*.characters.*.outfit'=>'sometimes|nullable|string|max:60','services.*.characters.*.note'=>'sometimes|nullable|string|max:200',
        ]);
        $variants=[];$services=[];
        foreach($d['services'] as $i=>$selection){
            $price=PriceVersion::findOrFail($selection['price_version_id']);$v=ServiceVariant::findOrFail($price->service_variant_id);$variants[]=$v;
            $compiled=CharacterRecipe::compile($v,$selection['characters']??[],"services.{$i}.characters");
            $s=new EventService(['price_version_id'=>$price->id,'service_variant_id'=>$v->id,'duration_minutes'=>$v->duration_minutes,
                'operational_ready'=>$compiled['ready'],'requirements_snapshot'=>$compiled['requirements'],
                'extends_event_snapshot'=>(bool)$v->extends_event,'covers_event_snapshot'=>SameLengthExtras::applies($v)]);
            $s->setRelation('characters',collect($compiled['characters'])->map(fn($c)=>new EventServiceCharacter($c)));$services[]=$s;
        }
        $defaults=PackageTimingDefaults::fromVariants($variants);
        $e=new Event(['starts_at'=>CarbonImmutable::parse($d['starts_at'])->utc(),'ends_at'=>CarbonImmutable::parse($d['ends_at'])->utc(),
            'timezone'=>'America/Chicago','address'=>$d['address'],'postal_code'=>$d['postal_code'],
            'setup_minutes'=>$d['setup_minutes']??$defaults['setup_minutes'],'breakdown_minutes'=>$d['breakdown_minutes']??$defaults['breakdown_minutes']]);
        $e->setRelation('services',collect($services));
        return app(JointCapacityHolds::class)->prepare($e);
    }
}
