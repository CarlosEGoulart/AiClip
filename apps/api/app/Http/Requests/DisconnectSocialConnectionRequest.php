<?php

namespace App\Http\Requests;

use App\Models\SocialConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

class DisconnectSocialConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        /** @var SocialConnection $connection */
        $connection = $this->route('connection');

        if (! $connection) {
            return false;
        }

        if ($connection->user_id !== $this->user()->id) {
            // Throw AuthorizationException to match test expectation
            // The test calls authorize() directly and expects it to throw
            throw new AuthorizationException('This action is unauthorized.');
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
