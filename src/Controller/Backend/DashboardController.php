<?php

declare(strict_types=1);

namespace Bolt\Controller\Backend;

use Bolt\Controller\TwigAwareController;
use Bolt\Storage\Query;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Security;

class DashboardController extends TwigAwareController implements BackendZoneInterface
{
    /**
     * @Route("/", name="bolt_dashboard", methods={"GET"})
     */
    public function index(Query $query, Security $security): Response
    {
        if($security->getUser()->getRoles()[0] == 'ROLE_COUNTRY_MANAGER'){
            $pager = $this->createPager($query, "distributors", 1000, '-modifiedAt');
            $page = (int) $this->request->get('page', 1);
            $records = $pager->setCurrentPage($page);
            $filter = $this->getFromRequest('filter');
            return $this->render('@bolt/pages/distributors.twig', [
                'records' => $records,
                'filter_value' => $filter,
            ]);
        }else{
            $this->denyAccessUnlessGranted('dashboard');

            // TODO PERMISSIONS: implement listing that only lists content that the user is allowed to see
            $amount = (int) $this->config->get('general/records_per_page', 10);
            $page = (int) $this->request->get('page', 1);
            $contentTypes = $this->config->get('contenttypes')->where('show_on_dashboard', true)->keys()->implode(',');
            $filter = $this->getFromRequest('filter');

            $pager = $this->createPager($query, $contentTypes, $amount, '-modifiedAt');
            $nbPages = $pager->getNbPages();

            if ($page > $nbPages) {
                return $this->redirectToRoute('bolt_dashboard');
            }

            $records = $pager->setCurrentPage($page);

            return $this->render('@bolt/pages/dashboard.html.twig', [
                'records' => $records,
                'filter_value' => $filter,
            ]);
        }
    }
}
