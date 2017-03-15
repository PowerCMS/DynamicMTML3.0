<?php
function smarty_block_mtifnotinarray ( $args, $content, &$ctx, &$repeat ) {
    $args[ 'else' ] = 1;
    return smarty_block_mtifinarray( $args, $content, $ctx, $repeat );
}
?>