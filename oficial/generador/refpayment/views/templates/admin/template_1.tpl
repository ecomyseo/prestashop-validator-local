{*
* 2007-2026 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2026 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="panel">
	<div class="row refpayment-header">
		<img src="{$module_dir|escape:'html':'UTF-8'}views/img/template_1_logo.png" class="col-xs-6 col-md-4 text-center" id="payment-logo" />
		<div class="col-xs-6 col-md-4 text-center">
			<h4>{l s='Online payment processing' mod='refpayment'}</h4>
			<h4>{l s='Fast - Secure - Reliable' mod='refpayment'}</h4>
		</div>
		<div class="col-xs-12 col-md-4 text-center">
			<a href="#" onclick="javascript:return false;" class="btn btn-primary" id="create-account-btn">{l s='Create an account now!' mod='refpayment'}</a><br />
			{l s='Already have an account?' mod='refpayment'}<a href="#" onclick="javascript:return false;"> {l s='Log in' mod='refpayment'}</a>
		</div>
	</div>

	<hr />
	
	<div class="refpayment-content">
		<div class="row">
			<div class="col-md-6">
				<h5>{l s='My payment module offers the following benefits' mod='refpayment'}</h5>
				<dl>
					<dt>&middot; {l s='Increase customer payment options' mod='refpayment'}</dt>
					<dd>{l s='Visa®, Mastercard®, Diners Club®, American Express®, Discover®, Network and CJB®, plus debit, gift cards and more.' mod='refpayment'}</dd>
					
					<dt>&middot; {l s='Help to improve cash flow' mod='refpayment'}</dt>
					<dd>{l s='Receive funds quickly from the bank of your choice.' mod='refpayment'}</dd>
					
					<dt>&middot; {l s='Enhanced security' mod='refpayment'}</dt>
					<dd>{l s='Multiple firewalls, encryption protocols and fraud protection.' mod='refpayment'}</dd>
					
					<dt>&middot; {l s='One-source solution' mod='refpayment'}</dt>
					<dd>{l s='Conveniance of one invoice, one set of reports and one 24/7 customer service contact.' mod='refpayment'}</dd>
				</dl>
			</div>
			
			<div class="col-md-6">
				<h5>{l s='FREE My Payment Module Glocal Gateway (Value of 400$)' mod='refpayment'}</h5>
				<ul>
					<li>{l s='Simple, secure and reliable solution to process online payments' mod='refpayment'}</li>
					<li>{l s='Virtual terminal' mod='refpayment'}</li>
					<li>{l s='Reccuring billing' mod='refpayment'}</li>
					<li>{l s='24/7/365 customer support' mod='refpayment'}</li>
					<li>{l s='Ability to perform full or patial refunds' mod='refpayment'}</li>
				</ul>
				<br />
				<em class="text-muted small">
					* {l s='New merchant account required and subject to credit card approval.' mod='refpayment'}
					{l s='The free My Payment Module Global Gateway will be accessed through log in information provided via email within 48 hours.' mod='refpayment'}
					{l s='Monthly fees for My Payment Module Global Gateway will apply.' mod='refpayment'}
				</em>
			</div>
		</div>

		<hr />
		
		<div class="row">
			<div class="col-md-12">
				<h4>{l s='Accept payments in the United States using all major credit cards' mod='refpayment'}</h4>
				
				<div class="row">
					<img src="{$module_dir|escape:'html':'UTF-8'}views/img/template_1_cards.png" class="col-md-6" id="payment-logo" />
					<div class="col-md-6">
						<h6 class="text-branded">{l s='For transactions in US Dollars (USD) only' mod='refpayment'}</h6>
						<p class="text-branded">{l s='Call 888-888-1234 if you have any questions or need more information!' mod='refpayment'}</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
