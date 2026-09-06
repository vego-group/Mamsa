<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Synthetic numbers only, from the block this project already
            // documents as non-real: config/test_mode.php uses +96655500000X
            // for its test accounts, so +966555###### extends the same space.
            //
            // The old value was '+9665' plus eight random digits — a VALID
            // Saudi mobile number belonging, quite possibly, to a real person.
            // That was harmless only for as long as nothing dialled it, and on
            // 2026-09-06 something did: the suite reached the FGC gateway on
            // every OTP test because phpunit.xml never overrode SMS_DRIVER.
            //
            // Http::preventStrayRequests() in Tests\TestCase is the guard that
            // stops the call. This is the second layer: if a request ever does
            // escape — a partially faked test, a seeder run by hand, a script
            // outside the suite — it should carry a number that reaches nobody.
            // Saudi numbering reserves no test range, so the honest best is a
            // block we control and have already declared synthetic.
            // The 9##### suffix keeps the factory clear of +96655500000X,
            // which config/test_mode.php reserves for the fixed test accounts.
            // Without the offset a factory user could be minted on the
            // superadmin's test number and shadow it — a test failure that
            // would look like an auth bug.
            'phone' => '+966555'.fake()->unique()->numerify('9#####'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
