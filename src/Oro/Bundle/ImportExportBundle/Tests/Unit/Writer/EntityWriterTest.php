<?php

namespace Oro\Bundle\ImportExportBundle\Tests\Unit\Writer;

use Akeneo\Bundle\BatchBundle\Entity\StepExecution;

use Doctrine\Common\Persistence\ManagerRegistry;

use Oro\Bundle\ImportExportBundle\Context\ContextRegistry;
use Oro\Bundle\ImportExportBundle\Writer\EntityDetachFixer;
use Oro\Bundle\ImportExportBundle\Writer\EntityWriter;

class EntityWriterTest extends \PHPUnit_Framework_TestCase
{
    /** @var \PHPUnit_Framework_MockObject_MockObject */
    protected $entityManager;

    /** @var \PHPUnit_Framework_MockObject_MockObject|ManagerRegistry */
    protected $registry;

    /** @var \PHPUnit_Framework_MockObject_MockObject|EntityDetachFixer */
    protected $detachFixer;

    /** @var \PHPUnit_Framework_MockObject_MockObject|ContextRegistry */
    protected $contextRegistry;

    /** @var EntityWriter */
    protected $writer;

    protected function setUp()
    {
        $this->entityManager = $this->getMockBuilder('Doctrine\ORM\EntityManager')
            ->disableOriginalConstructor()
            ->getMock();

        $this->registry = $this->getMock('Doctrine\Common\Persistence\ManagerRegistry');
        $this->registry->expects($this->any())
            ->method('getManager')
            ->willReturn($this->entityManager);
        $this->registry->expects($this->any())
            ->method('getManagerForClass')
            ->willReturn($this->entityManager);

        $this->detachFixer = $this->getMockBuilder('Oro\Bundle\ImportExportBundle\Writer\EntityDetachFixer')
            ->disableOriginalConstructor()
            ->getMock();

        $this->contextRegistry = $this->getMock('Oro\Bundle\ImportExportBundle\Context\ContextRegistry');

        $this->writer = new EntityWriter($this->registry, $this->detachFixer, $this->contextRegistry);
    }

    /**
     * @param array $configuration
     *
     * @dataProvider configurationProvider
     */
    public function testWrite($configuration)
    {
        $fooItem = $this->getMock('FooItem');
        $barItem = $this->getMock('BarItem');

        $this->detachFixer->expects($this->at(0))
            ->method('fixEntityAssociationFields')
            ->with($fooItem, 1);

        $this->detachFixer->expects($this->at(1))
            ->method('fixEntityAssociationFields')
            ->with($barItem, 1);

        $this->entityManager->expects($this->at(0))
            ->method('persist')
            ->with($fooItem);

        $this->entityManager->expects($this->at(1))
            ->method('persist')
            ->with($barItem);

        $this->entityManager->expects($this->at(2))
            ->method('flush');

        /** @var StepExecution $stepExecution */
        $stepExecution = $this->getMockBuilder('Akeneo\Bundle\BatchBundle\Entity\StepExecution')
            ->disableOriginalConstructor()
            ->getMock();

        $context = $this->getMock('Oro\Bundle\ImportExportBundle\Context\ContextInterface');
        $context->expects($this->once())
            ->method('getConfiguration')
            ->will($this->returnValue($configuration));

        $this->contextRegistry->expects($this->once())
            ->method('getByStepExecution')
            ->with($stepExecution)
            ->will($this->returnValue($context));

        if (empty($configuration[EntityWriter::SKIP_CLEAR])) {
            $this->entityManager->expects($this->at(3))
                ->method('clear');
        }

        $this->writer->setStepExecution($stepExecution);
        $this->writer->write([$fooItem, $barItem]);
    }

    /**
     * @return array
     */
    public function configurationProvider()
    {
        return [
            'no clear flag'    => [[]],
            'clear flag false' => [[EntityWriter::SKIP_CLEAR => false]],
            'clear flag true'  => [[EntityWriter::SKIP_CLEAR => true]],
        ];
    }
}
