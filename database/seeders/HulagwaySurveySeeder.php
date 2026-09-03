<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Database\Seeder;

class HulagwaySurveySeeder extends Seeder
{
    public function run(): void
    {
        $barangay = Barangay::first();
        if (! $barangay) {
            $this->command->warn('No barangays found, skipping Hulagway survey seed.');
            return;
        }

        $creator = User::whereHas('role', fn($q)=>$q->where('name','officer'))->first() ?? User::first();
        if (! $creator) {
            $this->command->warn('No user found, skipping.');
            return;
        }

        // Avoid duplicate
        $existing = Survey::where('title','Hulagway — Household Profile (Purok 4)')->first();
        if ($existing) {
            $this->command->info('Hulagway survey already exists.');
            return;
        }

        $survey = Survey::create([
            'title' => 'Hulagway — Household Profile (Purok 4)',
            'description' => 'Household profiling per Purok 4 samples (Silanga/Maquilao): 18 groups, 8 core hulagway metrics (Category 2 where applicable). One submission = one household with roster.',
            'type' => Survey::TYPE_HOUSEHOLD,
            'status' => Survey::STATUS_PUBLISHED,
            'barangay_id' => $barangay->id,
            'created_by' => $creator->id,
            'published_at' => now(),
            'public_token' => bin2hex(random_bytes(16)),
        ]);

        // Household-level questions only — identification via household.purok + barangay_id and member roster Head; no duplicate Household Head / Purok questions (per latest UI spec)
        $questions = [
            ['text'=>'Housing Condition','type'=>'single_choice','code'=>'housing','scope'=>'household','map'=>true,'options'=>['Good','Needs Repair','Poor'],'required'=>false],
            ['text'=>'Land Ownership','type'=>'single_choice','code'=>'land','scope'=>'household','map'=>true,'options'=>['Owned','Rented','Informal Settler'],'required'=>false],
            ['text'=>'Electricity Access','type'=>'single_choice','code'=>'electricity','scope'=>'household','map'=>true,'options'=>['Yes','No - Can Afford','No - Cannot Afford'],'required'=>false],
            ['text'=>'Water Access','type'=>'single_choice','code'=>'water','scope'=>'household','map'=>true,'options'=>['Piped','Well','None - Nearby Source','None - Far'],'required'=>false],
            ['text'=>'Toilet/Sanitation','type'=>'single_choice','code'=>'toilet','scope'=>'household','map'=>true,'options'=>['Flush','Pit','None','Communal'],'required'=>false],
            ['text'=>'Livelihood','type'=>'text','code'=>null,'scope'=>'household','map'=>false,'required'=>false],
            ['text'=>'Poor Family Category','type'=>'single_choice','code'=>'poor_cat2','scope'=>'household','map'=>true,'options'=>['Category 1 – Lisod pero naay source','Category 2 – Perting lisura','Not Applicable'],'required'=>false],
            ['text'=>'Live-in Status (Nag-live-in ba?)','type'=>'single_choice','code'=>'live_in','scope'=>'household','map'=>true,'options'=>['Yes','No'],'required'=>false],
            ['text'=>'If Live-in Yes: How many years living together?','type'=>'number','code'=>'live_in_years','scope'=>'household','map'=>false,'required'=>false],
            ['text'=>'If Live-in Yes: Reason why not married?','type'=>'text','code'=>'live_in_reason','scope'=>'household','map'=>false,'required'=>false],
            ['text'=>'Government Service You Like','type'=>'text','code'=>'gov_service_like','scope'=>'household','map'=>false,'required'=>false],
            ['text'=>'Government Service Satisfaction','type'=>'likert','code'=>'gov_satisfaction','scope'=>'household','map'=>false,'options'=>['1','2','3','4','5'],'required'=>false],
            ['text'=>'Suggestions','type'=>'text','code'=>'suggestions','scope'=>'household','map'=>false,'required'=>false],
        ];

        foreach ($questions as $idx=>$q) {
            $question = $survey->questions()->create([
                'question_text' => $q['text'],
                'type' => $q['type'],
                'is_required' => $q['required'],
                'order' => $idx,
                'code' => $q['code'],
                'data_scope' => $q['scope'],
                'map_enabled' => $q['map'],
            ]);
            foreach ($q['options'] ?? [] as $oIdx=>$label) {
                $question->options()->create(['label'=>$label,'order'=>$oIdx]);
            }
        }

        $this->command->info('Hulagway survey seeded: '.$survey->title.' ('.$survey->id.') with '.count($questions).' questions.');
    }
}
