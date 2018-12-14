<?php

namespace Statamic\Http\Controllers\CP;

use Statamic\API\URL;
use Statamic\API\User;
use Statamic\API\Email;
use Statamic\API\Config;
use Statamic\API\Helper;
use Statamic\API\Fieldset;
use Statamic\API\Blueprint;
use Statamic\API\UserGroup;
use Illuminate\Http\Request;
use Statamic\Fields\Validation;
use Statamic\Auth\PasswordReset;
use Illuminate\Http\Resources\Json\Resource;
use Statamic\Contracts\Auth\User as UserContract;

class UsersController extends CpController
{
    use GetsTaxonomiesFromFieldsets;

    /**
     * @var UserContract
     */
    private $user;

    public function index(Request $request)
    {
        $this->authorize('index', UserContract::class);

        if ($request->wantsJson()) {
            return $this->json($request);
        }

        return view('statamic::users.index');
    }

    protected function json($request)
    {
        $query = $request->group
            ? UserGroup::find($request->group)->queryUsers()
            : User::query();

        $users = $query
            ->orderBy($sort = request('sort', 'email'), request('order', 'asc'))
            ->paginate();

        $users->setCollection($users->getCollection()->supplement(function ($user) use ($request) {
            return ['deleteable' => $request->user()->can('delete', $user)];
        }));

        return Resource::collection($users)->additional(['meta' => [
            'sortColumn' => $sort,
            'columns' => [
                ['label' => 'name', 'field' => 'name'],
                ['label' => 'email', 'field' => 'email'],
            ],
        ]]);
    }

    /**
     * Create a new user
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $this->authorize('create', UserContract::class);

        $blueprint = Blueprint::find('user');

        return view('statamic::users.create', [
            'blueprint' => $blueprint,
            'values' => $blueprint->fields()->values()
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', UserContract::class);

        $blueprint = Blueprint::find('user');

        $fields = $blueprint->fields()->addValues($request->all())->process();

        $validation = (new Validation)->fields($fields)->withRules([
            'email' => 'required', // TODO: Needs to be more clever re: different logic for email as login
        ]);

        $request->validate($validation->rules());

        $values = array_except($fields->values(), ['email', 'groups', 'roles']);

        $user = User::make()
            ->email($request->email)
            // ->password('secret') // TODO: Either accept input, hash some garbage, or make password nullable in migration.
            ->data($values)
            ->roles($request->roles ?? [])
            ->groups($request->groups ?? [])
            ->save();

        return ['redirect' => $user->editUrl()];
    }

    public function edit($id)
    {
        $user = User::find($id);

        $this->authorize('edit', $user);

        $values = $user->blueprint()
            ->fields()
            ->addValues($user->data())
            ->preProcess()
            ->values();

        $values['email'] = $user->email();
        $values['roles'] = $user->roles()->map->id()->values()->all();
        $values['groups'] = $user->groups()->map->id()->values()->all();
        $values['status'] = $user->status();

        return view('statamic::users.edit', [
            'user' => $user,
            'values' => $values
        ]);
    }

    public function update(Request $request, $id)
    {
        $user = User::find($id);

        $this->authorize('edit', $user);

        $fields = $user->blueprint()->fields()->addValues($request->all())->process();

        $validation = (new Validation)->fields($fields)->withRules([
            'email' => 'required', // TODO: Needs to be more clever re: different logic for username as login
        ]);

        $request->validate($validation->rules());

        $values = array_except($fields->values(), ['email', 'groups', 'roles']);

        foreach ($values as $key => $value) {
            $user->set($key, $value);
        }

        $user
            ->email($request->email)
            ->roles($request->roles)
            ->groups($request->groups)
            ->save();

        return response('', 204);
    }

    public function destroy($user)
    {
        if (! $user = User::find($user)) {
            return $this->pageNotFound();
        }

        $this->authorize('delete', $user);

        $user->delete();

        return response('', 204);
    }

    public function getResetUrl($username)
    {
        $user = User::whereUsername($username);

        // Users can reset their own password
        if ($user !== User::getCurrent()) {
            $this->authorize('super');
        }

        $resetter = new PasswordReset;

        $resetter->user($user);

        return [
            'success' => true,
            'url' => $resetter->url()
        ];
    }

    public function sendResetEmail($username)
    {
        $user = User::whereUsername($username);

        if (! $user->email()) {
            return ['success' => false];
        }

        $resetter = new PasswordReset;

        $resetter->user($user);

        $resetter->send();

        return ['success' => true];
    }
}
