<?php
// Bulk add user to blogs
function add_user_to_blogs(){
	global $wpdb;

	echo "<div class='wrap'>";
	echo "<h1>Add User to blogs</h1>";

	if (isset($_POST['username']) && isset($_POST['blogpath'])) {
		$user = get_user_by('login', $_POST['username']);
		if ($user == false) {
			echo $_POST['username'] . " not found";
		} else {
			// explode
			$blogpath = $_POST['blogpath'];
			$blogpath = explode(PHP_EOL, $blogpath);

			$blogs = $wpdb->get_results("SELECT blog_id, domain, path FROM `" . $wpdb->blogs . "` ORDER BY blog_id DESC");
			if ($blogs) {
				$blogs_info = array();
				foreach ($blogs as $blog) {
					foreach ($blogpath as $path) {
						$path = str_replace("http://", "", $path);
						$path = str_replace("https://", "", $path);
						$path = explode("/", $path);
						$path = "/" . $path[1] . "/";
						// echo $path . "<br>";
						// echo $blog->path;
						if (stripos($blog->path, $path) !== false) {
							$slug = $blog->path;
							$id = get_id_from_blogname($slug);

							//ADD USER ID TO BLOG ID AS AN ADMINISTRATOR
							$blog_id = $blog->blog_id;
							$role = 'administrator';
							add_user_to_blog($blog_id, $user->ID, $role);
							$url = get_site_url($blog->blog_id);
							echo $user->user_login . " added to " . $url . "<br>";
						} else {
							// echo "error<br>";
						}
					}
					// break;
				}
			}
		}
	}
?>
	<form action="" id="adduser" method="post" novalidate="novalidate">
		<table class="form-table">
			<tbody>
				<tr class="form-field form-required">
					<th scope="row"><label for="username">Username</label></th>
					<td><input type="text" class="regular-text" name="username" id="username" autocapitalize="none" autocorrect="off" maxlength="60" value="<?php echo $_POST['username']; ?>"></td>
				</tr>
				<tr class="form-field form-required">
					<th scope="row"><label for="blogpath">Blog Path</label></th>
					<td><textarea class="regular-text" name="blogpath" id="blogpath" rows="10" cols="30"><?php echo $_POST['blogpath']; ?></textarea><br></td>
				</tr>
				<tr class="form-field">
					<td colspan="2">User will be added to the founded blogs.</td>
				</tr>
			</tbody>
		</table>
		<p class="submit"><input type="submit" name="add-user" id="add-user" class="button button-primary" value="Add User"></p>
	</form>
<?php
	echo "</div>";
}
