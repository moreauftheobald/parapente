<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HasFilterableIndex;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * BackOffice — gestion des utilisateurs.
 *
 * Garde-fous :
 *   - On empêche l'admin actuellement connecté de se rétrograder en
 *     'user' ou de se supprimer (sinon il perd son accès admin et
 *     personne ne peut plus rentrer si c'est le seul).
 *   - L'inscription publique reste désactivée — les comptes sont
 *     créés UNIQUEMENT via /admin/users/create ou la commande
 *     `php artisan admin:create`.
 */
class UserController extends Controller
{
    use HasFilterableIndex;

    private const SORTABLE = ['name', 'email', 'role', 'created_at'];

    public function index(Request $request): View
    {
        $query = User::query();

        $this->applySearch($query, $request->input('search'), ['name', 'email']);
        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        [$sort, $dir] = $this->applySorting($query, $request, self::SORTABLE, 'created_at', 'desc');

        $users = $query->paginate(50)->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'sort'  => $sort,
            'dir'   => $dir,
        ]);
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role'     => ['required', Rule::in(['admin', 'user'])],
        ]);

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => $data['password'],   // hashé via le cast 'hashed'
            'role'     => $data['role'],
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Utilisateur « {$user->name} » créé.");
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', [
            'user'    => $user,
            'isSelf'  => $user->id === auth()->id(),
        ]);
    }

    public function update(User $user, Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role'     => ['required', Rule::in(['admin', 'user'])],
            // Mot de passe optionnel : null = pas de changement
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ]);

        // Garde-fou : un admin ne peut pas se rétrograder lui-même
        if ($user->id === auth()->id() && $data['role'] !== 'admin') {
            return back()
                ->withInput()
                ->withErrors(['role' => 'Vous ne pouvez pas vous rétrograder vous-même.']);
        }

        $user->name  = $data['name'];
        $user->email = $data['email'];
        $user->role  = $data['role'];
        if (! empty($data['password'])) {
            $user->password = $data['password'];   // hashé via cast
        }
        $user->save();

        return redirect()
            ->route('admin.users.edit', $user)
            ->with('status', "Utilisateur « {$user->name} » enregistré.");
    }

    public function destroy(User $user): RedirectResponse
    {
        // Garde-fou : un admin ne peut pas se supprimer lui-même
        if ($user->id === auth()->id()) {
            return back()->with('status', 'Suppression refusée : vous ne pouvez pas vous supprimer vous-même.');
        }

        $name = $user->name;
        $user->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('status', "Utilisateur « {$name} » supprimé.");
    }
}
