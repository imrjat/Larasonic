<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Models\User;
use App\Enums\OauthProvider;
use App\Traits\AsFakeAction;
use InvalidArgumentException;
use App\Actions\Fortify\CreateNewUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\{DB, Validator};
use Laravel\Socialite\Two\User as SocialiteUser;
use App\Jobs\User\UpdateUserProfileInformationJob;
use Illuminate\Validation\{Rule, ValidationException};

class HandleOauthCallbackAction
{
    use AsFakeAction;

    public function handle(OauthProvider $provider, SocialiteUser $socialiteUser, ?User $authenticatedUser = null): User
    {
        return match (true) {
            $authenticatedUser instanceof User => $this->handleAuthenticatedUser($provider, $socialiteUser, $authenticatedUser),
            default => $this->handleUnauthenticatedUser($provider, $socialiteUser),
        };
    }

    private function handleAuthenticatedUser(OauthProvider $provider, SocialiteUser $socialiteUser, User $user): User
    {
        $this->validateAuthenticatedUserConnection($provider, $socialiteUser, $user);

        $this->updateUserProfile($user, $socialiteUser, $provider);

        return $user;
    }

    private function handleUnauthenticatedUser(OauthProvider $provider, SocialiteUser $socialiteUser): User
    {
        return DB::transaction(function () use ($provider, $socialiteUser): User {
            $existingUser = User::query()
                ->whereEmail($socialiteUser->getEmail())
                ->first();

            return match (true) {
                $existingUser instanceof User => $this->handleExistingUser($existingUser, $provider, $socialiteUser),
                default => $this->createNewUser($socialiteUser, $provider),
            };
        });
    }

    private function validateAuthenticatedUserConnection(OauthProvider $provider, SocialiteUser $socialiteUser, User $user): void
    {
        try {
            Validator::validate([
                'provider' => $provider->value,
                'provider_id' => $socialiteUser->getId(),
                'email' => $socialiteUser->getEmail(),
            ], [
                'provider' => [
                    'required',
                    'max:255',
                    Rule::enum(OauthProvider::class),
                    Rule::unique('oauth_connections')->where(
                        fn (Builder $query) => $query->where('provider', $provider->value)
                            ->where('provider_id', $socialiteUser->getId())
                    ),
                ],
                'email' => ['required', 'email', Rule::in([$user->email])],
            ], [
                'provider.unique' => __('This account from :provider is already connected to another account.', ['provider' => $provider->value]),
                'email.in' => __('The email address from this :provider does not match your account email.', ['provider' => $provider->value]),
            ]);
        } catch (ValidationException) {
            throw_if($socialiteUser->getEmail() !== $user->email, new InvalidArgumentException("The email address from this {$provider->value} does not match your account email."));

            throw new InvalidArgumentException('Validation error try again later.');
        }
    }

    private function handleExistingUser(User $user, OauthProvider $provider, SocialiteUser $socialiteUser): User
    {
        throw_unless($user->oauthConnections()->where('provider', $provider)->exists(), new InvalidArgumentException('Please login with existing auth provider and then link account'));

        $this->updateUserProfile($user, $socialiteUser, $provider);

        return $user;
    }

    private function createNewUser(SocialiteUser $socialiteUser, OauthProvider $provider): User
    {
        $user = (new CreateNewUser())->create([
            'name' => (string) $socialiteUser->getName(),
            'email' => (string) $socialiteUser->getEmail(),
            'terms' => (string) true,
        ]);

        $this->updateUserProfile($user, $socialiteUser, $provider);

        return $user;
    }

    private function updateUserProfile(User $user, SocialiteUser $socialiteUser, OauthProvider $provider): void
    {
        dispatch_sync(new UpdateUserProfileInformationJob($user, $socialiteUser, $provider));
    }
}
