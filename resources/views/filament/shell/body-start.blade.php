@auth
    @php($posPrefs = app(\App\Domain\Experience\Services\ExperiencePreferences::class)->for(auth()->user()))
    <script>document.documentElement.dataset.posDensity = @js($posPrefs['density'] ?? 'comfortable');</script>
@endauth
<a href="#pos-main" class="pos-skip-link">Skip to content</a>
