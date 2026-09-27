<?php

namespace App\Domain\Ai\Assistants;

use App\Domain\Ai\Services\AiAnswer;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Services\KnowledgeBase;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Support\Str;

/** Policy Assistant (§94): answers from the tenant knowledge base, published policies and settings — never from general knowledge. */
final class PolicyAssistant implements Assistant
{
    public function __construct(private readonly KnowledgeBase $kb, private readonly PolicyResolver $policies, private readonly SettingsRepository $settings) {}

    public function key(): string
    {
        return 'policy';
    }

    public function examples(): array
    {
        return ['What is the notice period?', 'How does work from home work?', 'What is the leave carry-forward rule?', 'Where do I report harassment?'];
    }

    public function answer(User $user, ?Employee $employee, string $question): AiAnswer
    {
        $sources = [];
        $facts = [];
        $parts = [];

        if ($employee) {
            if (preg_match('/notice/i', $question)) {
                $days = (int) $this->settings->get('exit.notice_days', 30);
                $parts[] = "The standard notice period is {$days} days from the resignation date.";
                $facts['notice_days'] = $days;
                $sources[] = ['label' => 'Setting exit.notice_days'];
            }
            foreach (['attendance' => ['grace', 'late', 'attendance', 'punch'], 'leave' => ['leave', 'carry', 'encash'], 'overtime' => ['overtime']] as $type => $keys) {
                if (Str::contains(strtolower($question), $keys) && ($version = $this->policies->resolve($type, $employee))) {
                    $settings = collect($version->settings ?? [])->except(['entitlements'])->filter(fn ($v) => is_scalar($v))->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_bool($v) ? ($v ? 'yes' : 'no') : $v))->take(8);
                    if ($settings->isNotEmpty()) {
                        $parts[] = 'Your '.$type.' policy ('.($version->policy?->name ?? $type).") says:\n- ".$settings->implode("\n- ");
                        $facts[$type.'_policy'] = $settings->all();
                        $sources[] = ['label' => ucfirst($type).' policy '.($version->policy?->name ?? '').' v'.$version->version];
                    }
                }
            }
        }

        $term = trim(preg_replace('/\b(what|is|the|how|does|do|i|a|an|of|for|to|in|my|our|can|where|policy|about|rule|rules)\b/i', ' ', $question));
        $term = trim(preg_replace('/\s+/', ' ', $term));
        $articles = $employee && $term !== '' ? $this->kb->visibleTo($employee, $term)->take(3) : collect();
        if ($articles->isEmpty() && $employee && $term !== '') {
            foreach (explode(' ', $term) as $word) {
                if (strlen($word) >= 4) {
                    $articles = $articles->merge($this->kb->visibleTo($employee, $word));
                }
            }
            $articles = $articles->unique('id')->take(3);
        }
        foreach ($articles as $article) {
            $snippet = Str::limit(trim(preg_replace('/\s+/', ' ', strip_tags(Str::markdown($article->body)))), 240);
            $parts[] = "From \"{$article->title}\": {$snippet}";
            $sources[] = ['label' => $article->title, 'detail' => 'Knowledge base v'.$article->version];
            $facts['articles'][] = ['title' => $article->title, 'snippet' => $snippet];
        }

        if ($parts === []) {
            return AiAnswer::text('I could not find that in the knowledge base or your policies. Try different words, or ask HR through a request.', 'kb_miss', [], [['label' => 'Knowledge base', 'url' => url('/admin/articles')], ['label' => 'Ask HR', 'url' => url('/admin/tickets')]]);
        }

        $actions = $articles->map(fn ($a) => ['label' => 'Read: '.$a->title, 'url' => url('/admin/articles/'.$a->id)])->values()->all();

        return new AiAnswer(implode("\n\n", $parts), $sources, $actions, 'kb_match', false, $facts);
    }
}
