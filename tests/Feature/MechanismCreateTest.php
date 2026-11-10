<?php

namespace Tests\Feature;

use App\Actions\Mechanisms\CreateMechanism;
use App\Jobs\RunMechanismPhase;
use App\Models\Mechanism;
use App\Models\MechanismRound;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MechanismCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/mechanisms')->assertRedirect('/login');
        $this->get('/mechanisms/create')->assertRedirect('/login');
    }

    public function test_the_production_list_has_a_button_to_create_a_mechanism(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/productions')
            ->assertOk()
            ->assertSeeInOrder(['制作を作る', '仕組みを作る'])
            ->assertSee(route('mechanisms.create'));
    }

    public function test_a_mechanism_is_created_with_the_first_round(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::mechanisms.create')
            ->set('goal', '登録した YouTube の料理動画から、2 人分の買い物リストを自動で作りたい')
            ->set('title', '買い物リストの仕組み')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect(route('mechanisms.show', Mechanism::query()->sole()));

        $mechanism = Mechanism::query()->sole();
        $this->assertSame('買い物リストの仕組み', $mechanism->title);
        $this->assertSame($user->id, $mechanism->owner_id);
        $this->assertSame(Mechanism::STATUS_ACTIVE, $mechanism->status);
        $this->assertSame(1, $mechanism->current_round);

        $round = $mechanism->rounds()->sole();
        $this->assertSame(1, $round->number);
        $this->assertSame(MechanismRound::PHASE_UNDERSTAND, $round->phase);
        $this->assertSame(MechanismRound::STATUS_QUEUED, $round->phase_status);
        Queue::assertPushed(RunMechanismPhase::class, fn (RunMechanismPhase $job) => $job->round->is($round) && $job->phase === MechanismRound::PHASE_UNDERSTAND);
    }

    public function test_the_title_defaults_to_the_start_of_the_goal(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::mechanisms.create')
            ->set('goal', "料理動画から買い物リストを作りたい\n分量は 2 人分")
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame('料理動画から買い物リストを作りたい', Mechanism::query()->sole()->title);
        $this->assertSame(Mechanism::TITLE_FROM_GOAL_LENGTH + 1, mb_strlen(CreateMechanism::titleFromGoal(str_repeat('あ', 100))));
    }

    public function test_the_goal_is_required(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::mechanisms.create')
            ->set('goal', '短い')
            ->call('create')
            ->assertHasErrors(['goal' => 'min']);

        $this->assertDatabaseCount('mechanisms', 0);
    }

    public function test_only_the_owner_can_see_a_mechanism(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $mechanism = app(CreateMechanism::class)->handle(['goal' => '料理動画から買い物リストを作りたい'], $owner);
        $other = Mechanism::factory()->create(['title' => '他の人の仕組み']);

        $this->actingAs($owner);
        $this->get(route('mechanisms.show', $mechanism))
            ->assertOk()
            ->assertSee('料理動画から買い物リストを作りたい')
            ->assertSee('① 目標の理解');
        $this->get(route('mechanisms.index'))
            ->assertOk()
            ->assertSee($mechanism->title)
            ->assertDontSee('他の人の仕組み');
        $this->get(route('mechanisms.show', $other))->assertNotFound();
    }
}
