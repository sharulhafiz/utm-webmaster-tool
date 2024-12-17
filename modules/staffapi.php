<?php
function penilaian_indeks_kesejahteraan_staf_utm() {
    if (is_page('borang-soal-selidik-kesejahteraan-staf-utm')) {
        ?>
        <!-- UTM Staff API -->
        <script>
            function runStaffApiScript() {
                jQuery(document).ready(function($) {
                    var $ = jQuery.noConflict();
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

                    jQuery('#field_nopekerja').keyup(function() {
                        var nopekerja = $(this).val();
                        if (nopekerja.length >= 4) {
                            get_staff_details(nopekerja);
                        }
                    });
                });
            }

            if (typeof jQuery === 'undefined') {
                var script = document.createElement('script');
                script.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
                script.type = 'text/javascript';
                script.onload = runStaffApiScript;
                document.head.appendChild(script);
            } else {
                runStaffApiScript();
            }
        </script>
        <!-- End UTM Staff API -->
        <?php
    }
}
add_action('wp_footer', 'penilaian_indeks_kesejahteraan_staf_utm');