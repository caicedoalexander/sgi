document.addEventListener('DOMContentLoaded', function () {
    var contractType = document.getElementById('contract-type');
    var orgWrapper = document.getElementById('org-temporal-wrapper');
    if (!contractType || !orgWrapper) {
        return;
    }
    var OBRA_LABOR = window.SPI_OBRA_LABOR || 'OBRA O LABOR DETERMINADA';
    function toggleOrg() {
        orgWrapper.style.display = contractType.value === OBRA_LABOR ? '' : 'none';
    }
    contractType.addEventListener('change', toggleOrg);
    toggleOrg();
});
