@include('errors.pos-layout', ['code' => '500', 'title' => 'Something went wrong on our side',
    'happened' => 'PeopleOS could not complete this request because of an unexpected error.',
    'means' => 'Most changes are saved in a single step, so a failed request usually leaves nothing half-saved. The error has been logged.',
    'todo' => 'Try again in a moment. If it keeps happening, tell your administrator what you were doing and when.'])
