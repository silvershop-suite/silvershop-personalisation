<%-- "Made to order — not returnable" notice. Works on a product page (where $PersonalisationActive
     is set) and on an order line / confirmation (where $IsPersonalised is set). Copy only, not enforcement. --%>
<% if $IsPersonalised || $PersonalisationActive %>
    <% require css('silvershop/personalisation: css/personalisation.css') %>
    <p class="personalisation-notice" role="note">
        <%t SilverShop\Personalisation.NotReturnable "Personalised to your order — made to order and not returnable." %>
    </p>
<% end_if %>
