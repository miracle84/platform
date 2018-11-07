<?php

namespace Oro\Bundle\ApiBundle\Processor\Shared;

use Oro\Bundle\ApiBundle\Config\MetaPropertiesConfigExtra;
use Oro\Bundle\ApiBundle\Processor\Context;
use Oro\Bundle\ApiBundle\Request\DataType;
use Oro\Bundle\ApiBundle\Request\ValueNormalizer;
use Oro\Component\ChainProcessor\ContextInterface;
use Oro\Component\ChainProcessor\ProcessorInterface;

/**
 * Checks whether the "meta" filter exists and if so,
 * adds the corresponding configuration extra into the context.
 * This filter is used to specify which entity meta properties should be returned.
 */
class HandleMetaPropertyFilter implements ProcessorInterface
{
    /** @var ValueNormalizer */
    protected $valueNormalizer;

    /**
     * @param ValueNormalizer $valueNormalizer
     */
    public function __construct(ValueNormalizer $valueNormalizer)
    {
        $this->valueNormalizer = $valueNormalizer;
    }

    /**
     * {@inheritdoc}
     */
    public function process(ContextInterface $context)
    {
        /** @var Context $context */

        $filterValue = $context->getFilterValues()->get(AddMetaPropertyFilter::FILTER_KEY);
        if (null === $filterValue) {
            // meta properties were not requested
            return;
        }
        $names = $this->valueNormalizer->normalizeValue(
            $filterValue->getValue(),
            DataType::STRING,
            $context->getRequestType(),
            true
        );
        if (empty($names)) {
            // meta properties were not requested
            return;
        }

        $names = (array)$names;
        $configExtra = $context->getConfigExtra(MetaPropertiesConfigExtra::NAME);
        if (null === $configExtra) {
            $configExtra = new MetaPropertiesConfigExtra();
            $context->addConfigExtra($configExtra);
        }

        foreach ($names as $name) {
            $configExtra->addMetaProperty($name);
        }
    }
}
