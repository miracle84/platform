<?php //@codingStandardsIgnoreFile?>
<input <?php echo $view['layout']->block($block, 'block_attributes') ?> type="<?php echo ($action === 'submit' || $action === 'reset') ? $action : 'button' ?>"<?php if (isset($name)): ?> name="<?php echo $name ?>"<?php endif ?><?php if (isset($value) || isset($text)): ?> value="<?php echo $view->escape(isset($value) ? $value : $view['layout']->text($text, $translation_domain)) ?>"<?php endif ?>/>
