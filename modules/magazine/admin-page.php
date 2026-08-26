<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Magazine admin page — submenu under Settings.
 */

add_action( 'admin_menu', function () {
    add_options_page(
        'Magazine Items',
        'Magazine Items',
        'manage_options',
        'magazine-items',
        'utm_magazine_admin_page'
    );
});

add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( 'settings_page_magazine-items' !== $screen->id ) {
        return;
    }
    ?>
    <style>
        .magazine-table{width:100%;border-collapse:collapse;margin-top:1em}
        .magazine-table th,.magazine-table td{padding:8px 12px;border:1px solid #ccc;text-align:left}
        .magazine-table th{background:#f1f1f1}
        .magazine-row--draft td{opacity:.7}
        .magazine-form{margin:1.5em 0;padding:1em;background:#fff;border:1px solid #ccc;max-width:600px}
        .magazine-form label{display:block;margin:6px 0 2px;font-weight:600}
        .magazine-form input[type=text],.magazine-form select{width:100%;padding:4px}
        .magazine-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:.85em}
        .magazine-status--published{background:#d4edda;color:#155724}
        .magazine-status--draft{background:#fff3cd;color:#856404}
        .magazine-msg{padding:10px;margin:10px 0;border:1px solid}
        .magazine-msg--ok{background:#d4edda;border-color:#28a745;color:#155724}
        .magazine-msg--err{background:#f8d7da;border-color:#dc3545;color:#721c24}
        .magazine-listing__actions{white-space:nowrap}
    </style>
    <?php
});

/**
 * Enqueue JS for AJAX uploads.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    if ( 'settings_page_magazine-items' !== $hook ) {
        return;
    }

    wp_enqueue_script(
        'utm-magazine-admin',
        false,
        [],
        '1.0.0'
    );
    wp_add_inline_script( 'utm-magazine-admin', '
    (function(){
        document.addEventListener("DOMContentLoaded", function(){
            var form  = document.getElementById("magazine-upload-form");
            var msg   = document.getElementById("magazine-msg");
            var dels  = document.querySelectorAll(".magazine-btn-delete");

            if(form){
                form.addEventListener("submit", function(e){
                    e.preventDefault();
                    var fd = new FormData(form);
                    fd.append("action","magazine_upload");
                    fd.append("nonce","' . wp_create_nonce( 'magazine_nonce' ) . '");
                    msg.className="magazine-msg"; msg.textContent="Uploading…";

                    fetch(ajaxurl,{method:"POST",body:fd})
                    .then(r=>r.json()).then(d=>{
                        if(d.success){
                            msg.className="magazine-msg magazine-msg--ok";
                            msg.textContent="✓ Published: "+d.data.url;
                            setTimeout(()=>location.reload(),1500);
                        } else {
                            msg.className="magazine-msg magazine-msg--err";
                            var errs = d.data.errors ? d.data.errors.join("\\n") : d.data.message;
                            msg.textContent="✗ "+errs;
                        }
                    }).catch(()=>{
                        msg.className="magazine-msg magazine-msg--err";
                        msg.textContent="Network error.";
                    });
                });
            }

            dels.forEach(btn=>{
                btn.addEventListener("click",function(){
                    if(!confirm("Delete this magazine item and all its files?")) return;
                    var fd=new FormData();
                    fd.append("action","magazine_delete");
                    fd.append("slug",btn.dataset.slug);
                    fd.append("nonce","' . wp_create_nonce( 'magazine_nonce' ) . '");
                    fetch(ajaxurl,{method:"POST",body:fd})
                    .then(r=>r.json()).then(d=>{location.reload();});
                });
            });
        });
    });
    ' );
});

/**
 * Main admin page renderer.
 */
function utm_magazine_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }

    $items = utm_magazine_get_items();
    ?>
    <div class="wrap">
        <h1>Magazine Items</h1>
        <p>Upload AI-generated static-site ZIP packages and publish them under <code>/magazine/&lt;slug&gt;/</code>.</p>

        <div id="magazine-msg"></div>

        <div class="magazine-form">
            <h2>Add / Update Magazine Item</h2>
            <form id="magazine-upload-form" enctype="multipart/form-data" method="post">
                <label for="mag-title">Title</label>
                <input type="text" id="mag-title" name="title" placeholder="Magazine item title" required>

                <label for="mag-slug">Slug (URL segment)</label>
                <input type="text" id="mag-slug" name="slug" pattern="[a-z0-9\-]+" placeholder="my-article" required>

                <label for="mag-zip">ZIP package (max 50 MB)</label>
                <input type="file" id="mag-zip" name="magazine_zip" accept=".zip" required>

                <label for="mag-status">Status</label>
                <select id="mag-status" name="status">
                    <option value="draft">Draft</option>
                    <option value="published">Published</option>
                </select>

                <br>
                <button type="submit" class="button button-primary">Upload & Publish</button>
            </form>
        </div>

        <h2>Published Items</h2>
        <?php if ( empty( $items ) ) : ?>
            <p>No magazine items yet.</p>
        <?php else : ?>
        <table class="magazine-table">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>ZIP</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $items as $item ) :
                $row_class = 'published' !== $item->status ? ' magazine-row--draft' : '';
                $status_cls = 'magazine-status magazine-status--' . $item->status;
            ?>
                <tr class="<?php echo esc_attr( $row_class ); ?>">
                    <td><code><?php echo esc_html( $item->slug ); ?></code></td>
                    <td><?php echo esc_html( $item->title ); ?></td>
                    <td><span class="<?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $item->status ); ?></span></td>
                    <td><?php echo esc_html( $item->zip_name ); ?></td>
                    <td><?php echo esc_html( $item->updated_at ?: $item->created_at ); ?></td>
                    <td class="magazine-listing__actions">
                        <?php if ( 'published' === $item->status ) : ?>
                            <a href="<?php echo esc_url( home_url( '/magazine/' . $item->slug . '/' ) ); ?>"
                               target="_blank" class="button button-small">View</a>
                        <?php endif; ?>
                        <button class="button button-small magazine-btn-delete"
                                data-slug="<?php echo esc_attr( $item->slug ); ?>">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}
