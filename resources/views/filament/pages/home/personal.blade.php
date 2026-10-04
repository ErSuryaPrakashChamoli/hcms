{{-- UX.16 employee: things about your own record, from real PeopleOS data (requests, probation, documents, pay, manager). --}}
@include('filament.pages.home.signals', ['items' => $h['personal'], 'title' => 'For you', 'verb' => 'Open'])
