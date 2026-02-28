<?php
declare(strict_types=1);

namespace controllers;

use models\persons as PersonsModel;

final class persons extends Controller
{


    /*
     * Index action.
     *
     * Renders a simple list action for the persons controller.
     */

    public function index(): void
    {
        $items = [];
        try {
            $model = new PersonsModel();
            $items = $model->allSafe();
        } catch (\Throwable) {
            $items = [];
        }

        $this->render([
            'items' => $items,
            'count' => count($items),
        ]);
    }



    /*
     * Show action.
     *
     * Renders details for one person id.
     */

    public function show(string $id): void
    {
        $record = null;
        try {
            $model = new PersonsModel();
            $record = $model->findSafe($id);
        } catch (\Throwable) {
            $record = null;
        }

        $this->render([
            'id' => $id,
            'record' => $record,
            'exists' => $record !== null,
        ]);
    }
}
