<?php
declare(strict_types=1);

namespace App\Controller;

use App\Attribute\Permission;
use App\Controller\Trait\CatalogCrudTrait;
use App\Controller\Trait\ExcelWizardTrait;

class EducationLevelsController extends AppController
{
    use CatalogCrudTrait;
    use ExcelWizardTrait;

    public array $paginate = ['limit' => 15, 'maxLimit' => 15];

    /**
     * Lista los niveles educativos.
     *
     * @return void
     */
    #[Permission(action: 'view')]
    public function index()
    {
        $educationLevels = $this->paginate($this->EducationLevels);

        $this->set(compact('educationLevels'));
    }

    /**
     * Muestra un nivel educativo.
     *
     * @param string|null $id Education level id.
     * @return void
     */
    #[Permission(action: 'view')]
    public function view(?string $id = null)
    {
        $educationLevel = $this->EducationLevels->get($id);

        $this->set(compact('educationLevel'));
    }

    /**
     * Crea un nivel educativo.
     *
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'add')]
    public function add()
    {
        $educationLevel = $this->EducationLevels->newEmptyEntity();
        $result = $this->_catalogSave(
            $this->EducationLevels,
            $educationLevel,
            __('El nivel educativo ha sido guardado.'),
            __('No se pudo guardar el nivel educativo. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('educationLevel'));
    }

    /**
     * Edita un nivel educativo.
     *
     * @param string|null $id Education level id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'edit')]
    public function edit(?string $id = null)
    {
        $educationLevel = $this->EducationLevels->get($id);
        $result = $this->_catalogSave(
            $this->EducationLevels,
            $educationLevel,
            __('El nivel educativo ha sido actualizado.'),
            __('No se pudo actualizar el nivel educativo. Intente de nuevo.'),
        );
        if ($result !== null) {
            return $result;
        }

        $this->set(compact('educationLevel'));
    }

    /**
     * Elimina un nivel educativo.
     *
     * @param string|null $id Education level id.
     * @return \Cake\Http\Response|null
     */
    #[Permission(action: 'delete')]
    public function delete(?string $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);
        $educationLevel = $this->EducationLevels->get($id);
        if ($this->EducationLevels->delete($educationLevel)) {
            $this->Flash->success(__('El nivel educativo ha sido eliminado.'));
        } else {
            $this->Flash->error(__('No se pudo eliminar el nivel educativo. Intente de nuevo.'));
        }

        return $this->redirect(['action' => 'index']);
    }
}
