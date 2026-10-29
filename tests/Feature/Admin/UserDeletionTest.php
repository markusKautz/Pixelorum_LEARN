<?php

use App\Actions\Users\DeleteUser;
use App\Exceptions\LastAdminException;
use App\Models\LearningPath;
use App\Models\Lesson;
use App\Models\LessonCompletion;
use App\Models\PathProgress;
use App\Models\Question;
use App\Models\QuestionResponse;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

use function Pest\Laravel\mock;

/**
 * Baut einen Lehrling mit vollem Datenschatten: Fortschritt, Versuche,
 * Antworten, Sitzung, Einladung an seine Adresse.
 *
 * @return array{learner: User, group: Team, quiz: Quiz}
 */
function learnerWithFullTrail(): array
{
    $path = LearningPath::factory()->published()->verified()->create();
    $lesson = Lesson::factory()->for($path)->create();
    $quiz = Quiz::factory()->pathFinal($path)->published()->create();
    $question = Question::factory()->for($path)->create();

    $group = Team::factory()->create(['name' => 'Lehrjahr 2026']);
    $learner = User::factory()->apprentice($group)->create(['name' => 'Lena Lehrling']);

    LessonCompletion::factory()->create(['user_id' => $learner->id, 'lesson_id' => $lesson->id]);
    PathProgress::factory()->create(['user_id' => $learner->id, 'learning_path_id' => $path->id]);

    $attempt = QuizAttempt::factory()->create(['user_id' => $learner->id, 'quiz_id' => $quiz->id]);
    QuestionResponse::factory()->create(['quiz_attempt_id' => $attempt->id, 'question_id' => $question->id]);

    DB::table('sessions')->insert([
        'id' => 'session-of-lena',
        'user_id' => $learner->id,
        'ip_address' => '198.51.100.7',
        'user_agent' => 'Mozilla/5.0',
        'payload' => 'x',
        'last_activity' => now()->timestamp,
    ]);

    TeamInvitation::factory()->create([
        'team_id' => Team::factory()->create()->id,
        'invited_by' => $learner->id,
        'email' => $learner->email,
    ]);

    // Ein echter Reset-Token, kein handgeschriebener Hash: nur so prüft der Test
    // dieselbe Tabelle und dieselbe Verschlüsselung, die auch `DeleteUser` räumt.
    Password::createToken($learner);

    return ['learner' => $learner, 'group' => $group, 'quiz' => $quiz];
}

test('deleting a user leaves no personal data behind', function () {
    ['learner' => $learner] = learnerWithFullTrail();

    $personalTeamIds = $learner->teams()->where('teams.is_personal', true)->pluck('teams.id');

    app(DeleteUser::class)->handle($learner);

    $this->assertDatabaseMissing('users', ['id' => $learner->id]);

    // Von den Fremdschlüsseln erledigt …
    $this->assertDatabaseMissing('team_members', ['user_id' => $learner->id]);
    $this->assertDatabaseMissing('lesson_completions', ['user_id' => $learner->id]);
    $this->assertDatabaseMissing('quiz_attempts', ['user_id' => $learner->id]);
    $this->assertDatabaseMissing('path_progress', ['user_id' => $learner->id]);
    $this->assertDatabaseEmpty('question_responses');

    // … und die vier, die *nicht* kaskadieren und deshalb einzeln geräumt werden.
    $this->assertDatabaseMissing('sessions', ['user_id' => $learner->id]);
    $this->assertDatabaseMissing('team_invitations', ['email' => $learner->email]);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $learner->email]);

    foreach ($personalTeamIds as $teamId) {
        // Nicht bloß soft-gelöscht: die Zeile trägt den Klarnamen.
        expect(Team::withTrashed()->whereKey($teamId)->exists())->toBeFalse();
    }
});

test('deleting a user keeps the content they touched', function () {
    ['learner' => $learner, 'quiz' => $quiz] = learnerWithFullTrail();

    // Beide `nullOnDelete`-Verweise zeigen auf den Nutzer, der gleich verschwindet
    // — sonst bewiese der Test nur, dass die Referenz eines *anderen* bleibt.
    $path = LearningPath::factory()->published()->create(['verified_by' => $learner->id]);
    $ownQuiz = Quiz::factory()->create(['created_by' => $learner->id]);

    app(DeleteUser::class)->handle($learner);

    // Inhalte bleiben, nur der Urheber-Verweis fällt auf NULL (nullOnDelete).
    $this->assertDatabaseHas('quizzes', ['id' => $ownQuiz->id, 'created_by' => null]);
    $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
    $this->assertDatabaseHas('learning_paths', ['id' => $path->id, 'verified_by' => null]);
});

test('a reset link of a deleted account cannot claim the next account with that email', function () {
    ['learner' => $learner] = learnerWithFullTrail();

    $email = $learner->email;
    $token = Password::createToken($learner);

    app(DeleteUser::class)->handle($learner);

    // Dieselbe Adresse wird später erneut eingeladen und legt ein neues Konto an.
    // `DatabaseTokenRepository` sucht den Token allein über die E-Mail — bliebe die
    // alte Zeile stehen, setzte ein Link des gelöschten Kontos dessen Passwort.
    $successor = User::factory()->create(['email' => $email]);

    expect(Password::tokenExists($successor, $token))->toBeFalse();
});

test('an admin deletes a learner from the user list', function () {
    $admin = User::factory()->admin()->create();
    ['learner' => $learner] = learnerWithFullTrail();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('confirmDelete', $learner->id)
        ->set('deleteConfirmation', 'Lena Lehrling')
        ->call('deleteUser')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('users', ['id' => $learner->id]);
});

test('a mistyped name does not delete', function () {
    $admin = User::factory()->admin()->create();
    ['learner' => $learner] = learnerWithFullTrail();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('confirmDelete', $learner->id)
        ->set('deleteConfirmation', 'Lena Lehrlin')
        ->call('deleteUser')
        ->assertHasErrors('deleteConfirmation');

    $this->assertDatabaseHas('users', ['id' => $learner->id]);
});

test('an admin cannot delete their own account from the list', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('confirmDelete', $admin->id)
        ->set('deleteConfirmation', $admin->name)
        ->call('deleteUser')
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $admin->id]);
});

test('the last admin cannot be deleted', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => app(DeleteUser::class)->handle($admin))->toThrow(LastAdminException::class);

    // Die Transaktion rollt zurück: weder Konto noch persönliches Team fehlen.
    $this->assertDatabaseHas('users', ['id' => $admin->id]);
    expect($admin->teams()->where('teams.is_personal', true)->exists())->toBeTrue();
});

test('of two admins one can be deleted, then the other cannot', function () {
    $first = User::factory()->admin()->create();
    $second = User::factory()->admin()->create();

    app(DeleteUser::class)->handle($second);

    $this->assertDatabaseMissing('users', ['id' => $second->id]);

    expect(fn () => app(DeleteUser::class)->handle($first))->toThrow(LastAdminException::class);

    $this->assertDatabaseHas('users', ['id' => $first->id]);
});

test('the user list reports a refused deletion of the last admin', function () {
    // Zwischen Vorprüfung und Löschung kann ein anderer Admin verschwunden
    // sein; diesen Wettlauf stellt die Aktion fest, nicht die Liste.
    $admin = User::factory()->admin()->create();
    $other = User::factory()->admin()->create(['name' => 'Bruno Ausbildner']);

    mock(DeleteUser::class)->shouldReceive('handle')->once()->andThrow(new LastAdminException);

    Livewire::actingAs($admin)
        ->test('pages::admin.users.index')
        ->call('confirmDelete', $other->id)
        ->set('deleteConfirmation', 'Bruno Ausbildner')
        ->call('deleteUser')
        ->assertOk()
        ->assertDispatched('toast-show');

    $this->assertDatabaseHas('users', ['id' => $other->id]);
});

test('the delete button is hidden for the acting admin', function () {
    $admin = User::factory()->admin()->create();
    $group = Team::factory()->create();
    $learner = User::factory()->apprentice($group)->create();

    $component = Livewire::actingAs($admin)->test('pages::admin.users.index');

    // Genau ein Löschen-Knopf: für den Lehrling, nicht für den Admin selbst.
    expect(substr_count($component->html(), 'data-test="user-delete-button"'))->toBe(1)
        ->and($component->instance()->users->pluck('id')->all())
        ->toContain($learner->id, $admin->id);
});

test('apprentices cannot delete users', function () {
    $group = Team::factory()->create();
    $learner = User::factory()->apprentice($group)->create();
    $victim = User::factory()->apprentice($group)->create();

    Livewire::actingAs($learner)
        ->test('pages::admin.users.index')
        ->assertForbidden();

    $this->assertDatabaseHas('users', ['id' => $victim->id]);
});
