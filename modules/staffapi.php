<?php
// hook on page slug penilaian-indeks-kesejahteraan-staf-utm at registrar.utm.my
function penilaian_indeks_kesejahteraan_staf_utm(){
    if (is_page('borang-soal-selidik-kesejahteraan-staf-utm')) {
        ?>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

        <script>
            // jQuery loaded, run code
            jQuery(document).ready(function($) {
                var $ = jQuery.noConflict();
                // jQuery code here
                console.log("UTM Staff API loaded");

                function get_staff_details(nopekerja) {
                    jQuery.ajax({
                        url: 'https://www.utm.my/directory/json.php?q=staff_details_bynopekerja&nopekerja=' + nopekerja,
                        dataType: 'json',
                        success: function(data) {
                            console.log(data);
                            data = data[0];
                            $('#field_nama').val(data.NAMA);
                            $('#field_jawatan').val(data.JAWATAN);
                            $('#field_email_rasmi').val(data.EMAIL_RASMI);
                            $('#field_fakulti').val(data.FAKULTI);
                        }
                    });
                }
                // monitor field_nopekerja
                jQuery('#field_nopekerja').keyup(function() {
                    var nopekerja = $(this).val();
                    get_staff_details(nopekerja);
                });
            });
        </script>
        <?php
    }
}
add_filter('template_redirect', 'penilaian_indeks_kesejahteraan_staf_utm');
