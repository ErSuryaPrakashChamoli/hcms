<?php

namespace App\Http\Controllers;

use App\Domain\Performance\Services\FeedbackNominations;
use App\Domain\Platform\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * HROne setup parity HP-8h (PF-4): a client gives 360 feedback through the link e-mailed to them — no sign-in. The link is
 * signed (the `signed` middleware checks the tenant and token were not altered); the tenant it names must be open; the
 * token must belong to a client still asked in an open round, before the window closes, and works once (FeedbackNominations
 * ::byToken). Nothing about the employee is shown beyond their name; answers are checked against the round's form.
 */
class Feedback360ExternalController extends Controller
{
    public function __invoke(Request $request, int $tenant, string $token, TenantContext $tenants): Response
    {
        $organisation = Tenant::query()->find($tenant);
        if ($organisation === null || ! $organisation->isAccessible()) {
            return $this->page(['state' => 'unavailable'], 404);
        }

        return $tenants->runAs($organisation, function () use ($request, $token, $organisation) {
            $service = app(FeedbackNominations::class);
            $nomination = $service->byToken($token);
            if ($nomination === null) {
                return $this->page(['state' => 'unavailable'], 410);
            }
            $nomination->loadMissing('employee.person');
            $template = $nomination->round->templateFor($nomination->relation);
            $view = ['state' => 'form', 'organisation' => $organisation->name, 'name' => $nomination->external_name, 'about' => $nomination->employee?->person?->full_name,
                'due' => $nomination->round->window_closes_on->format('d M Y'), 'questions' => $template['questions'] ?? [], 'action' => $request->fullUrl(), 'old' => [], 'error' => null];
            if (! $request->isMethod('post')) {
                return $this->page($view);
            }
            try {
                if ($request->boolean('decline')) {
                    $service->decline($nomination, null);

                    return $this->page(['state' => 'declined']);
                }
                $service->respond($nomination, (array) $request->input('answers', []), null);

                return $this->page(['state' => 'thanks']);
            } catch (RuntimeException $e) {
                return $this->page(['old' => (array) $request->input('answers', []), 'error' => $e->getMessage()] + $view, 422);
            }
        });
    }

    /** @param  array<string, mixed>  $data */
    private function page(array $data, int $status = 200): Response
    {
        return response()->view('feedback360.external', $data + ['state' => 'unavailable'], $status)
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store, private');
    }
}
