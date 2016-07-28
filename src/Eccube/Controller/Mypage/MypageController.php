<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) 2000-2015 LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace Eccube\Controller\Mypage;

use Eccube\Application;
use Eccube\Common\Constant;
use Eccube\Controller\AbstractController;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Exception\CartException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MypageController extends AbstractController
{
    /**
     * ログイン画面.
     *
     * @param Application $app
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function login(Application $app, Request $request)
    {
        if ($app->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $app->redirect($app->url('mypage'));
        }

        /* @var $form \Symfony\Component\Form\FormInterface */
        $builder = $app['form.factory']
            ->createNamedBuilder('', 'customer_login');

        if ($app->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            $Customer = $app->user();
            if ($Customer) {
                $builder->get('login_email')->setData($Customer->getEmail());
            }
        }

        $event = new EventArgs(
            array(
                'builder' => $builder,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::FRONT_MYPAGE_MYPAGE_LOGIN_INITIALIZE, $event);

        $form = $builder->getForm();

        return $app->render('Mypage/login.twig', array(
            'error' => $app['security.last_error']($request),
            'form' => $form->createView(),
        ));
    }

    /**
     * マイページ
     *
     * @param Application $app
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function index(Application $app, Request $request)
    {
        $Customer = $app['user'];

        /* @var $softDeleteFilter \Eccube\Doctrine\Filter\SoftDeleteFilter */
        $softDeleteFilter = $app['orm.em']->getFilters()->getFilter('soft_delete');
        $softDeleteFilter->setExcludes(array(
            'Eccube\Entity\ProductClass',
        ));

        $event = new EventArgs(
            array(
                'Customer' => $Customer,
            ),
            $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::FRONT_MYPAGE_MYPAGE_INDEX_SEARCH, $event);

        return $app->render('Mypage/index.twig', array());
    }


    /**
     * お気に入り商品を表示する.
     *
     * @param Application $app
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function favorite(Application $app, Request $request)
    {
        $BaseInfo = $app['eccube.repository.base_info']->get();

        if ($BaseInfo->getOptionFavoriteProduct() == Constant::ENABLED) {
            $Customer = $app->user();

            // paginator
            $qb = $app['eccube.repository.product']->getFavoriteProductQueryBuilderByCustomer($Customer);

            $event = new EventArgs(
                array(
                    'qb' => $qb,
                    'Customer' => $Customer,
                ),
                $request
            );
            $app['eccube.event.dispatcher']->dispatch(EccubeEvents::FRONT_MYPAGE_MYPAGE_FAVORITE_SEARCH, $event);

            $pagination = $app['paginator']()->paginate(
                $qb,
                $request->get('pageno', 1),
                $app['config']['search_pmax']
            );

            return $app->render('Mypage/favorite.twig', array(
                'pagination' => $pagination,
            ));
        } else {
            throw new NotFoundHttpException();
        }
    }

    /**
     * お気に入り商品を削除する.
     *
     * @param Application $app
     * @param Request $request
     * @param $id
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function delete(Application $app, Request $request, $id)
    {
        $this->isTokenValid($app);

        $Customer = $app->user();

        $Product = $app['eccube.repository.product']->find($id);

        $event = new EventArgs(
            array(
                'Customer' => $Customer,
                'Product' => $Product,
            ), $request
        );
        $app['eccube.event.dispatcher']->dispatch(EccubeEvents::FRONT_MYPAGE_MYPAGE_DELETE_INITIALIZE, $event);

        if ($Product) {
            $app['eccube.repository.customer_favorite_product']->deleteFavorite($Customer, $Product);

            $event = new EventArgs(
                array(
                    'Customer' => $Customer,
                    'Product' => $Product,
                ), $request
            );
            $app['eccube.event.dispatcher']->dispatch(EccubeEvents::FRONT_MYPAGE_MYPAGE_DELETE_COMPLETE, $event);
        }

        return $app->redirect($app->url('mypage_favorite'));
    }
}
