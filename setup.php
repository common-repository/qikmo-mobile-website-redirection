<img src="<?=plugin_dir_url( __FILE__ )?>/logo.png" width="200" />
<h2>Qikmo Setup page</h2>

<? if (isset($_POST['version']) && !$error) { ?>
<div id="message" class="updated below-h2"><p>Configuration saved, API is good</p></div>
<? } ?>

<? if ($error) { ?>
<div id="message" class="error below-h2"><p><?=esc_html($error)?></p></div>
<? } ?>

<form name="qikmo" action="" method="post">
    <input type="hidden" name="version" value="1">

    <table width="790" cellspacing="0" class="form-table">
        <tr>
            <th>Mobile domain</th>
            <td>http://<input type="text" name="domain" style="width:200px;"></td>
        </tr>

        <tr>
            <th>Redirect tablet devices to </th>
            <td>
                <select name="tablet_redirect">
                    <option value="desktop">Desktop site</option>
                    <option value="mobile">Mobile site</option>
                </select>
                <br/>
                <i>*Tablet users prefer browsing the desktop site. Only select 'Mobile Site' if you're sure you want to redirect tablet users to the mobile site</i>
            </td>
        </tr>

        <tr>
            <th colspan=2><b>Handset API parameters</b></th>
        </tr>

        <tr>
            <th>Username</th>
            <td><input type="text" name="username" style="width:200px;"></td>
        </tr>

        <tr>
            <th>Secret</th>
            <td><input type="password" name="secret" style="width:200px;"></td>
        </tr>

        <tr>
            <th>SiteID</th>
            <td><input type="text" name="site_id" style="width:200px;"></td>
        </tr>

        <tr>
            <td align="left" colspan=2>
                <input type="submit" value="Save & Check API">&nbsp;&nbsp;&nbsp;
                <a id="show_doc" href="#">Open instructions</a>
            </td>
        </tr>
    </table>
</form>


<br/>

<div id="doc" style="display:none;">
    <h2>Instructions</h2>

    <ol>
        <li>Modify client site DNS zone by adding new CNAME record:<br/>
            <i>m    CNAME mobile.previewmymobile.com</i>
        </li>
        <li>Handset API parameters. Please enter the information Qikmo has provided you for these fields.</li>
        <li>Click <b>Save&amp;check API</b> to save and check plugin configuration</li>
    </ol>
</div>

<script>
    jQuery('form[name=qikmo]').deserialize(<?= json_encode($option) ?>, {isPHPnaming:true});

    jQuery('#show_doc').click(function(e) {
        e.preventDefault();
        jQuery('#doc').slideDown();
    })
</script>
