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

namespace Eccube\Service;

use Doctrine\DBAL\LockMode;
use Underbar\ArrayImpl as _;
use Eccube\Application;
use Eccube\Common\Constant;
use Eccube\Entity\Customer;
use Eccube\Entity\Delivery;
use Eccube\Entity\MailHistory;
use Eccube\Entity\Order;
use Eccube\Entity\OrderDetail;
use Eccube\Entity\Product;
use Eccube\Entity\ProductClass;
use Eccube\Entity\ShipmentItem;
use Eccube\Entity\Shipping;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Exception\CartException;
use Eccube\Exception\ShoppingException;
use Eccube\Util\Str;



class ScrapingService
{
    /** @var \Eccube\Application */
    public $app;

    /** @var \Eccube\Entity\BaseInfo */
    protected $BaseInfo;

    /** @var  \Doctrine\ORM\EntityManager */
    protected $em;

    public function __construct(Application $app )
    {
        $this->app = $app;
        $this->BaseInfo = $app['eccube.repository.base_info']->get();
    }

    /**
     * スクレイピングの起動
     *
     * 1 girlId => 'ステータス状態'のハッシュを取得
     * 2 girlIdが存在しているか否かの確認し、存在していない場合、登録
     * 3
     * 4
     */
    public function action()
    {

        $totalGirlHash = $this->getParsedHTMLContents(Constant::DMM_URL);
        $totalGirlIdList = array_keys( $totalGirlHash );

        $this->isExistGirlAndRegist( $totalGirlIdList );

    }

    /**
     * メインのコントローラー(ここで処理を行う)
     *
     * @param スクレイピング対象のURL
     * @return 女性のidのハッシュ
     */
    private function getParsedHTMLContents( $url = "" )
    {

        if ( empty ( $url )) return false;

        $html = file_get_contents ( $url );

        if (! empty ( $html )) {

            $dom = \DomDocument::loadHTML ( $html );
            $xml = simplexml_import_dom ( $dom );
            //女子データを稼働状況ごとに取得する
            $workingStatueArr =['waiting','party','twoshot'];
            $totalGirlHash;
            foreach ( $workingStatueArr as $workingStatus ) {
                $selector = 'anchor_' . $workingStatus;
                $waitingList = $xml->xpath ( '//div[@id="' . $selector . '"]/ul/li' );
                $girlsListafterExtract = $this->getGirlIdList( $waitingList );
                $girlsList = _::pluck($girlsListafterExtract, 'id');
                $totalGirlHash[$workingStatus] = $girlsList;
            }
            //最終的にid=>working_statusの状態にする
            $girlIdHashFinal = $this->convertGrilIdData( $totalGirlHash );
            return $girlIdHashFinal;

        } else {
            return false;
        }

    }

    /**
     * チャットガールガールがすでに登録されているかの確認
     * 登録されていなければ登録する
     *
     * @param unknown $totalGirlIdHash
     */
    private function isExistGirlAndRegist( $totalGirlIdList )
    {
        $chatgirlIdHash = $this->getRegistredChatGirlId();

        foreach ( $totalGirlIdList as $girlId ) {

            if( isset( $chatgirlIdHash[$girlId]) !== true ) {
                //idの登録なし
                $this->registChatGirl( $girlId );
            } else {

            }
        }

    }

    private function registChatGirl( $girlId )
    {
        $girlId = "739124";
        $detailUrl = $this->getChatGirlDetailPageUrl( $girlId );
        $html      = file_get_contents ( $detailUrl );

        if (! empty ( $html )) {
            $dom = \DomDocument::loadHTML ( $html );
            $xml = simplexml_import_dom ( $dom );
            $this->getGirlsProperty( $xml );
        }
    }

    private function getGirlsProperty( $xml )
    {
        $nameEle = $xml->xpath ( '//div[contains(@class,"char-name")]/p[@class="name"]' );
        $name = $this->getPropertyFromElement( $nameEle );

        $imgEle = $xml->xpath ( '//div[@class="cg-tmb"]' );
        $image = $this->getPropertyFromElement( $imgEle );

    }

    private function getChatGirlDetailPageUrl( $girId )
    {
    	return Constant::DMM_URL ."-/chat-room/=/character_id=". $girId . "/";
    }

    /**
     * チャットガールガールがすでに登録されているかの確認
     * 登録されていなければ登録する
     *
     * @param unknown $chatgirlHash idのハッシュ
     *
     **/
    private function getRegistredChatGirlId()
    {
    	$productList= $this->app['eccube.repository.product']->getProductAndProductClass();

        $chatgirlIdHash = [];
        foreach( $productList as $Product ) {
            $ProductClass = $Product->getProductClasses();
            if( !empty($ProductClass[0]->getCode())){
                $chatgirlIdHash[$ProductClass[0]->getCode()] = 1;
            }
        }
        return $chatgirlIdHash;
    }

    /**
     * 女性リストの取得
     *
     * @param unknown $girlsList 女性のデータが入ったDOMデータ
     * @return multitype:id/classを格納した女性のリスト
     */
    private function getGirlIdList( $girlsList =[] ) {
        $girlsListafterExtract=[];

        foreach ( $girlsList as $girlEle) {

            $idElement = $girlEle->attributes();
            $girlData =  $this->getPropertyFromElement( $idElement );
            if( $girlData !== false) {
                $girlsListafterExtract[] = $girlData;
            }
        }
        return $girlsListafterExtract;
    }

    /**
     * XML要素からプロパティを取得する
     *
     * @param $idElement DOM要素
     * @return id/classを格納したクラス / false(取得失敗)
     */
    private function getPropertyFromElement( $idElement = [] ) {

        foreach ( $idElement as $attr => $property ) {
            if( !empty( $property ) ) {
                return $property;
            }
        }
        return false;
    }

    /**
     * workingstatus=>idListから id =>workingstatusの状態に変更をする
     *
     * @param unknown $totalGirlHash workingstatus=>idList
     * @return id =>workingstatusのハッシュ
     */
    private function convertGrilIdData( $totalGirlHash =[]) {
        $girlIdHashFinal;
        foreach( $totalGirlHash as $workingStatus => $girlList ) {
            foreach ( $girlList as $girlId){
                $girlIdHashFinal[$girlId] = $workingStatus;
            }
        }
        return $girlIdHashFinal;
    }

    /**
     * ログイン状態と非ログイン状態のスタッフを分ける
     *
     * @param unknown $girlIdHashFinal ログインユーザーのcharacter_id
     * @param unknown $allCharacterIdList character_idのデータ
     * @return ログイン/非ログインごとのユーザーのデータ
     */
    private function getLoginStaffUserList( $girlIdHashFinal, $allCharacterIdList ) {

        $UserList =[
                'login'    => [],
                'not_login'    =>[]
        ];

        foreach ( $allCharacterIdList as $characterId ) {

            if( isset($girlIdHashFinal[$characterId]) === true ) {
                #ログインしているユーザー
                $UserList['login'][] = [
                'character_id'   => $characterId,
                'working_status' => $this->getWorkingStatusNum( $girlIdHashFinal[$characterId])
                ];
            } else {
                #ログインしていないユーザー
                $UserList['not_login'][] = [
                'character_id'   => $characterId
                ];
            }
        }
        return $UserList;
    }

    /**
     * ログイン時の状態を文字列から数字で返す
     *
     * @param string $working_status_str 文字列waiting(1),party(2),twoshot(3)
     * @return number 数値
     */
    private function getWorkingStatusNum( $working_status_str ="") {

        $working_status_num;
        switch( $working_status_str){
            case 'waiting':
                $working_status_num = 1;
                break;
            case 'party':
                $working_status_num = 2;
                break;
            case 'twoshot':
                $working_status_num = 3;
                break;
            default:
                break;
        }
        return $working_status_num;
    }

    /**
     * ログインデータを記録する
     *
     * @param unknown $userList ログイン者と非ログイン者のデータ
     */
    private function registLoginStaffData( $userList =[]){

        foreach ( $userList['login'] as $userData ) {
            $this->divLoginStatus( $userData ,true );
        }

        foreach ( $userList['not_login'] as $userData2 ) {
            $this->divLoginStatus( $userData2 ,false );
        }
        $sqlLog = $this->getDataSource()->getLog(false, false);
        debug($sqlLog , false);

    }

    /**
     * ログインステータスの判定とデータの更新
     *
     * @param unknown $userData ユーザーデータ(user_idの入ったデータ)
     * @param string $isLogin true(ログイン中)/false(ログインしていない)
     */
    private function divLoginStatus($userData = [], $isLogin = true) {

        if ($isLogin === true) {
            //ログインユーザー
            //今現在ログインをしていて
            $hasLoginData = $this->hasLogin( $userData['character_id']);

            if( $hasLoginData !== false ) {
                //ステータス変更あり
                if( $hasLoginData['working_status'] != $userData['working_status'] ) {
                    //以前のステータス情報を閉じる
                    $this->updateUserLoginStatus( $hasLoginData, "2");
                    //新規の記録の場合は何もしない
                    $this->updateUserLoginStatus( $userData, "1");
                }
                //ステータス変更ない場合は何もしない

            } else {
                //ログイン記録がない場合は新規の記録
                $this->updateUserLoginStatus( $userData, "1");
            }

        } else {
            //非ログインユーザー
            //今現在ログインをしていなくて前回処理時にログインがある→ログイン終了をする
            $hasLoginData = $this->hasLogin( $userData['character_id']);
            if( $hasLoginData !== false ) {
                $this->updateUserLoginStatus( $hasLoginData, "2");
            }
        }
    }

    /**
     * ログイン中か否か
     *
     * @param string $character_id キャラクターID
     * @return boolean true(ログイン中) / false (ログインしていない)
     */
    private function hasLogin( $character_id = null ) {
        $hasLoginData = $this->find ( 'first', [
                'conditions' => [
                        'character_id' => $character_id,
                        'login_status' => 1
                ]
        ] );
        return ( count($hasLoginData) > 0 ) ? $hasLoginData["Logintime"] : false;
    }


    /**
     * ログインステータスを更新する(新規ログインの開始/既存ログインの終了)
     *
     * @param string $userData (新規ログイン開始時はcharacter_d / 既存ログイン終了時はLogintimeのid)
     * @param string $status 1=ログイン開始 2=ログイン終了
     * @return true(成功) / false (失敗)
     */
    private function updateUserLoginStatus( $userData = null, $status ="" ) {
        $data = [
                'login_status'     => $status
        ];

        if( empty($status) ) return false;

        if( empty( $userData['character_id']) && empty($userData['id']) ) {
            return false;
        }

        switch( $status ){
            case '1':
                //新規ログイン記録
                //userDataはUserから取得したデータ
                $data['character_id'] = $userData['character_id'];
                $data['login_start_time'] = date('Y-m-d H:i:s');
                $data['login_status'] = 1;
                $data['working_status'] = $userData['working_status'];
                break;
            case '2':
                //ログイン終了
                //userDataはログイン中のLogintimeのデータ
                $data['id'] = $userData['id'];
                $data['login_end_time'] = date('Y-m-d H:i:s');
                $data['login_status'] = 2;
                break;
            default:
                break;
        }

        $this->create();
        $this->save( $data);
        return true;
    }

    /**
     * ある対象期間(yyyy/MM)のログイン時間を取得する
     *
     * @param string $targetId 対象者id
     * @param string $targetMonthVal 対象期間(yyyy/MM)
     * @return int ログイン時間(秒)
     */
    private function getLoginSumTimeByTargetMonth( $targetId="" ,$targetMonthVal="") {

        if( empty( $targetId) ) return false;

        $dateUtility = new DateUtility();
        list( $startTime, $endTime ) = $dateUtility->getMonthStartAndEnd( $targetMonth );

        $conditions =[
                'fields' =>[
                        'SUM(UNIX_TIMESTAMP(Logintime.login_end_time)-UNIX_TIMESTAMP(Logintime.login_start_time)) as login_sum_time',
                ],
                'conditions' =>[
                        'Logintime.character_id' => $targetId,
                        'Logintime.login_status' => 2,
                        'Logintime.login_end_time >= ' => $startTime,
                        'Logintime.login_end_time <= ' => $endTime
                ],
        ];

        $loginData = $this->find('first', $conditions );

        if (!empty( $loginData[0]['login_sum_time'] ) ) {
            return $loginData[0]['login_sum_time'];
        } else {
            return false;
        }
    }

}