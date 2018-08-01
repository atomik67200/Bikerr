<?php
/**
 * Created by PhpStorm.
 * User: wilder
 * Date: 05/06/18
 * Time: 21:28
 */

namespace AppBundle\Service;

use AppBundle\Entity\Membre;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping as ORM;

use MangoPay;
use MangoPay\Libraries\Exception;
use MangoPay\Libraries\ResponseException;


class MangoPayApi
{

    private $connexionApi;
    private $urlServer;

    //Appel a l'API MangoPay
    public function __construct()
    {
        // create object to manage MangoPay API
        $this->connexionApi = new MangoPay\MangoPayApi();
        // login : password : temp directory
        $this->connexionApi->Config->ClientId = 'aurelgouilhers';
        $this->connexionApi->Config->ClientPassword = 'wr42AYOg5LU5OE3dn10qNrbfsDC7iYeRHu3N4Gjw3KtGDuSC1V';
        $this->connexionApi->Config->TemporaryFolder = __DIR__."/../../../TEMP_MANGOPAY";
        //declaration reoute retour pour recuperation card_Id object
        $this->urlServer = 'http' . ( isset($_SERVER['HTTPS']) ? 's' : '' ) . '://' . $_SERVER['HTTP_HOST'];
        $this->urlServer .= substr($_SERVER['REQUEST_URI'], 0, strripos($_SERVER['REQUEST_URI'], ' ') + 1);

    }

    public function CheckIdMangopay(Membre $membre,EntityManagerInterface $em){

        if($membre->getEmail() == null
            || $membre->getPays()->getAlpha2() == null
            || $membre->getPrenom() == null
            || $membre->getNom() == null
            || $membre->getDateNaissance() == null)
        {
            //on ne pourra pas créer l'id mango pay sans les informations alors on sort
            return $membre;
        }

        if ((null == $membre->getIdMangopay()) && (null == $membre->getIdWallet()))
        {

            $membre->setIdMangopay($this->CreateNaturalUser($membre));
            $membre->setIdWallet($this->CreateWallet($membre));
            $em->persist($membre);
            $em->flush();
            return $membre;
        }else
        {
            return $membre;
        }

    }

    public function CreateNaturalUser(Membre $membre)
    {
        $MangoUser = new MangoPay\UserNatural();
        $MangoUser->Email = $membre->getEmail();
        $MangoUser->FirstName = $membre->getPrenom();
        $MangoUser->LastName = $membre->getNom();
        $MangoUser->Birthday = $membre->getDateNaissance()->getTimestamp();
        $MangoUser->Nationality = $membre->getPays()->getAlpha2();
        $MangoUser->CountryOfResidence = $membre->getPays()->getAlpha2();
        $MangoUser = $this->connexionApi->Users->Create($MangoUser); //creation naturalUser with those parameter
        return $MangoUser->Id;
    }

    public function CreateWallet(Membre $membre)
    {
        // CREATION WALLET NATURAL USER
        $Wallet = new \MangoPay\Wallet();
        $Wallet->Owners = array($membre->getIdMangopay());
        $Wallet->Description = "Wallet of " . $membre->getPrenom() . " " . $membre->getNom();
        $Wallet->Currency = "EUR";
        return $this->connexionApi->Wallets->Create($Wallet)->Id;

    }

    public function getBalance(Membre $membre)
    {
        return $this->connexionApi->Wallets->Get($membre->getIdWallet())->Balance;
    }

    //creation objet CARD
    public function CardRegistration(Membre $membre)
    {
        $cardRegister = new \MangoPay\CardRegistration();
        $cardRegister->UserId = $membre->getIdMangopay();
        $cardRegister->CardType = "CB_VISA_MASTERCARD"; //on écrit le card type en dur car on a pas encore le type de carte
        $cardRegister->Currency = "EUR";
        return $this->connexionApi->CardRegistrations->Create($cardRegister);
    }

    // creation objet CardID
    public function CardUpdate(\MangoPay\CardRegistration $carte, $registrationId)
    {

        $CardRegistration = $this->connexionApi->CardRegistrations->Get($carte->Id);
        $CardRegistration->RegistrationData = 'data=' . $registrationId;
        $CardUpdate = $this->connexionApi->CardRegistrations->Update($CardRegistration);
        return $this->connexionApi->Cards->Get($CardUpdate->CardId);
    }

    //création d'une  Card direct PayIn
    public function PayIn(Membre $membre, \MangoPay\Card $CardObject, $caution, $assurance, $id_transaction)
    {
        //on calcul les fees de 5% sur la caution;
        $fees = ($caution * 5)/100;
        $PayIn = new \MangoPay\PayIn();
        $PayIn->CreditedWalletId = $membre->getIdWallet();
        $PayIn->AuthorId = $membre->getIdMangopay();
        $PayIn->PaymentType = \MangoPay\PayInPaymentType::Card;
        $PayIn->PaymentDetails = new \MangoPay\PayInPaymentDetailsCard();
        $PayIn->PaymentDetails->CardType = "CB_VISA_MASTERCARD";
        $PayIn->DebitedFunds = new \MangoPay\Money();
        $PayIn->DebitedFunds->Currency = "EUR";
        $PayIn->DebitedFunds->Amount = ($caution+$assurance)*100;//on multiplie par 100 car MP prends en centimes
        $PayIn->Fees = new \MangoPay\Money();
        $PayIn->Fees->Currency = "EUR";
        $PayIn->Fees->Amount = (int)(($fees+$assurance)*100);//on multiplie par 100 car MP prends en centimes
        $PayIn->ExecutionType = \MangoPay\PayInExecutionType::Web;
        $PayIn->PaymentDetails = new \MangoPay\PayInPaymentDetailsCard();
        $PayIn->PaymentDetails->CardType = $CardObject->CardType;
        $PayIn->PaymentDetails->CardId = $CardObject->Id;
        $PayIn->ExecutionDetails = new \MangoPay\PayInExecutionDetailsDirect();
        $PayIn->ExecutionDetails->ReturnURL = $this->urlServer."paiement/check_transaction/".$id_transaction;     //support@mangopay.com
        $PayIn->ExecutionDetails->SecureModeReturnURL = $this->urlServer."paiement/check_transaction/".$id_transaction;
        $PayIn->ExecutionDetails->SecureMode = "DEFAULT";
        $PayIn->ExecutionDetails->Culture = "FR";
        return $this->connexionApi->PayIns->Create($PayIn);
}


    //creation BankAccount Object pour effectuer les PayOut
    public function InitBankAccount(Membre $membre ,$iban, $bic, $titulaire, $adresse,EntityManagerInterface $em)
    {
        /*if($membre->getIdBankAccount() != null)
        {
            return $membre->getIdBankAccount();
        }*/
        $UserId = $membre->getIdMangopay();
        $BankAccount = new \MangoPay\BankAccount();
        $BankAccount->UserId=$membre->getIdMangopay();
        $BankAccount->Type = "IBAN";
        $BankAccount->Details = new MangoPay\BankAccountDetailsIBAN();
        $BankAccount->Details->IBAN = $iban;
        $BankAccount->Details->BIC = $bic;
        $BankAccount->OwnerName = $titulaire;
        $BankAccount->OwnerAddress = new \MangoPay\Address();
        $BankAccount->OwnerAddress->AddressLine1 = $adresse;
        $BankAccount->OwnerAddress->AddressLine2 = "";
        $BankAccount->OwnerAddress->City = $membre->getVille();
        $BankAccount->OwnerAddress->Country = $membre->getPays()->getAlpha2();
        $BankAccount->OwnerAddress->PostalCode = $membre->getCodePostal();
        $BankAccount->OwnerAddress->Region = "";
        //$BankAccount->Active = true;
        $membre->setIdBankAccount($this->connexionApi->Users->CreateBankAccount($UserId, $BankAccount)->Id);
        $em->persist($membre);
        $em->flush();
        return $membre->getIdBankAccount();
        // return $this->connexionApi->Users->CreateBankAccount($UserId, $BankAccount);
    }

    //cloturer transfert d'argent \MangoPay\BankAccount
    public function PayOut(Membre $membre, $amount,$fees)
    {
        //dump($this->connexionApi->Clients->GetWallet()->Balance);
        //dump($membre->getIdBankAccount());
        $PayOut = new \MangoPay\PayOut();
        $PayOut->AuthorId = $membre->getIdMangopay();
        $PayOut->DebitedWalletId = $membre->getIdWallet();
        $PayOut->DebitedFunds = new \MangoPay\Money();
        $PayOut->DebitedFunds->Currency = "EUR";
        $PayOut->DebitedFunds->Amount = $amount*100; // on multiplie par 100 car MP est en centime
        $PayOut->Fees = new \MangoPay\Money();
        $PayOut->Fees->Currency = "EUR";
        $PayOut->Fees->Amount = $fees; //les fees sont seulement sur les PayIn
        $PayOut->PaymentType = \MangoPay\PayOutPaymentType::BankWire;
        $PayOut->MeanOfPaymentDetails = new \MangoPay\PayOutPaymentDetailsBankWire();
        $PayOut->MeanOfPaymentDetails->BankAccountId = $membre->getIdBankAccount();
        //dump($PayOut);
        return $this->connexionApi->PayOuts->Create($PayOut);
    }

    //verifie le statut paiement
    public function CheckPayIn($transactionId)
    {
        //dump($transactionId);
        $payInStatus = $this->connexionApi->PayIns->Get($transactionId)->Status;
        //dump ($payInStatus);
        return $payInStatus;
    }


}
