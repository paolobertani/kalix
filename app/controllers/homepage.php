<?php
declare(strict_types=1);

namespace controllers;

final class homepage extends Controller
{


    /*
     * Homepage action.
     *
     * Renders the localized Kalix welcome page.
     */

    public function index(): void
    {
        $this->render([
            'name' => 'Paolo',
            'framework' => 'Kalix',
            'lang' => $this->lang,
        ]);
    }
}
