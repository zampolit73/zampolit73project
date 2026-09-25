<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['username', 'password', 'role'];
    protected $hidden = ['password', 'remember_token'];

    public function vacancyInvestigations(): HasMany
    {
        return $this->hasMany(VacancyInvestigation::class);
    }

    public function roleLabel(): string
    {
        return $this->role === 'admin' ? 'Администратор' : 'Пользователь';
    }
}
