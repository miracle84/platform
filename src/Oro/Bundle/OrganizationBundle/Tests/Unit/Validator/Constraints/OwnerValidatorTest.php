<?php

namespace Oro\Bundle\OrganizationBundle\Tests\Unit\Validator\Constraints;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Oro\Bundle\OrganizationBundle\Entity\Manager\BusinessUnitManager;
use Oro\Bundle\OrganizationBundle\Tests\Unit\Fixture\Entity\BusinessUnit;
use Oro\Bundle\OrganizationBundle\Tests\Unit\Fixture\Entity\Entity;
use Oro\Bundle\OrganizationBundle\Tests\Unit\Fixture\Entity\Organization;
use Oro\Bundle\OrganizationBundle\Tests\Unit\Fixture\Entity\User;
use Oro\Bundle\OrganizationBundle\Validator\Constraints\Owner;
use Oro\Bundle\OrganizationBundle\Validator\Constraints\OwnerValidator;
use Oro\Bundle\SecurityBundle\Acl\AccessLevel;
use Oro\Bundle\SecurityBundle\Acl\Domain\OneShotIsGrantedObserver;
use Oro\Bundle\SecurityBundle\Acl\Voter\AclVoter;
use Oro\Bundle\SecurityBundle\Authentication\TokenAccessorInterface;
use Oro\Bundle\SecurityBundle\Owner\Metadata\OwnershipMetadata;
use Oro\Bundle\SecurityBundle\Owner\Metadata\OwnershipMetadataProviderInterface;
use Oro\Bundle\SecurityBundle\Owner\OwnerTreeInterface;
use Oro\Bundle\SecurityBundle\Owner\OwnerTreeProvider;
use Oro\Component\Testing\Validator\AbstractConstraintValidatorTest;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraint;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 */
class OwnerValidatorTest extends AbstractConstraintValidatorTest
{
    /** @var \PHPUnit\Framework\MockObject\MockObject|ManagerRegistry */
    private $doctrine;

    /** @var \PHPUnit\Framework\MockObject\MockObject|OwnershipMetadataProviderInterface */
    private $ownershipMetadataProvider;

    /** @var \PHPUnit\Framework\MockObject\MockObject|AuthorizationCheckerInterface */
    private $authorizationChecker;

    /** @var \PHPUnit\Framework\MockObject\MockObject|TokenAccessorInterface */
    private $tokenAccessor;

    /** @var \PHPUnit\Framework\MockObject\MockObject|OwnerTreeProvider */
    private $ownerTreeProvider;

    /** @var \PHPUnit\Framework\MockObject\MockObject|AclVoter */
    private $aclVoter;

    /** @var \PHPUnit\Framework\MockObject\MockObject|BusinessUnitManager */
    private $businessUnitManager;

    /** @var Entity */
    private $testEntity;

    /** @var User */
    private $currentUser;

    /** @var Organization */
    private $currentOrg;

    protected function setUp()
    {
        $this->doctrine = $this->createMock(ManagerRegistry::class);
        $this->ownershipMetadataProvider = $this->createMock(OwnershipMetadataProviderInterface::class);
        $this->authorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->tokenAccessor = $this->createMock(TokenAccessorInterface::class);
        $this->ownerTreeProvider = $this->createMock(OwnerTreeProvider::class);
        $this->aclVoter = $this->createMock(AclVoter::class);
        $this->businessUnitManager = $this->createMock(BusinessUnitManager::class);

        $this->testEntity = new Entity();
        $this->currentOrg = new Organization();
        $this->currentOrg->setId(1);
        $this->currentUser = new User();
        $this->currentUser->setId(10);

        $this->tokenAccessor->expects(self::any())
            ->method('getUser')
            ->willReturn($this->currentUser);
        $this->tokenAccessor->expects(self::any())
            ->method('getUserId')
            ->willReturn($this->currentUser->getId());
        $this->tokenAccessor->expects(self::any())
            ->method('getOrganization')
            ->willReturn($this->currentOrg);

        parent::setUp();
    }

    /**
     * {@inheritdoc}
     */
    protected function createValidator()
    {
        return new OwnerValidator(
            $this->doctrine,
            $this->ownershipMetadataProvider,
            $this->authorizationChecker,
            $this->tokenAccessor,
            $this->ownerTreeProvider,
            $this->aclVoter,
            $this->businessUnitManager
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function createContext()
    {
        $this->constraint = new Owner();
        $this->propertyPath = null;

        return parent::createContext();
    }

    /**
     * @param int $id
     *
     * @return User
     */
    private function createUser($id)
    {
        $user = new User();
        $user->setId($id);

        return $user;
    }

    /**
     * @param int $id
     *
     * @return BusinessUnit
     */
    private function createBusinessUnit($id)
    {
        $businessUnit = new BusinessUnit();
        $businessUnit->setId($id);

        return $businessUnit;
    }

    /**
     * @param int $id
     *
     * @return Organization
     */
    private function createOrganization($id)
    {
        $organization = new Organization();
        $organization->setId($id);

        return $organization;
    }

    /**
     * @expectedException \Symfony\Component\Validator\Exception\UnexpectedTypeException
     */
    public function testValidateForInvalidConstraintType()
    {
        $this->validator->validate($this->testEntity, $this->createMock(Constraint::class));
    }

    public function testValidateForNull()
    {
        $this->doctrine->expects(self::never())
            ->method('getManagerForClass');

        $this->validator->validate(null, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidateForNotManageableEntity()
    {
        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn(null);
        $this->ownershipMetadataProvider->expects(self::never())
            ->method('getMetadata');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidateForNonAclProtectedEntity()
    {
        $ownershipMetadata = new OwnershipMetadata();

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($this->createMock(EntityManagerInterface::class));
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidWithNullOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $owner = null;
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::never())
            ->method('getUnitOfWork');
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);

        $this->aclVoter->expects(self::never())
            ->method('addOneShotIsGrantedObserver');
        $this->authorizationChecker->expects(self::never())
            ->method('isGranted');
        $entityMetadata->expects(self::never())
            ->method('getIdentifierValues');
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidWithNotChangedOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);

        $owner = $this->createUser(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => $owner]);

        $this->aclVoter->expects(self::never())
            ->method('addOneShotIsGrantedObserver');
        $this->authorizationChecker->expects(self::never())
            ->method('isGranted');
        $entityMetadata->expects(self::never())
            ->method('getIdentifierValues');
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testInvalidBecauseAccessDenied()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = null;

        $owner = $this->createUser(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testValidExistingEntityWithUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createUser(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->businessUnitManager->expects(self::once())
            ->method('canUserBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(true);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidExistingEntityWithBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createBusinessUnit(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->businessUnitManager->expects(self::once())
            ->method('canBusinessUnitBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(true);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidExistingEntityWithOrganizationOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('ORGANIZATION', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createOrganization(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->getUserOrganizationIdsExpectation([2, 3, $owner->getId()]);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testInvalidExistingEntityWithUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createUser(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->businessUnitManager->expects(self::once())
            ->method('canUserBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(false);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testInvalidExistingEntityWithBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createBusinessUnit(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->businessUnitManager->expects(self::once())
            ->method('canBusinessUnitBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(false);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testInvalidExistingEntityWithOrganizationOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('ORGANIZATION', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createOrganization(123);
        $this->testEntity->setId(234);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('ASSIGN', $this->testEntity)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([$this->testEntity->getId()]);
        $this->getUserOrganizationIdsExpectation([2, 3]);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testValidNewEntityWithUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createUser(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::once())
            ->method('canUserBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(true);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidNewEntityWithBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createBusinessUnit(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::once())
            ->method('canBusinessUnitBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(true);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidNewEntityWithOrganizationOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('ORGANIZATION', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createOrganization(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->getUserOrganizationIdsExpectation([2, 3, $owner->getId()]);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testInvalidNewEntityWithUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createUser(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::once())
            ->method('canUserBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(false);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testInvalidNewEntityWithBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createBusinessUnit(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::once())
            ->method('canBusinessUnitBeSetAsOwner')
            ->with($this->currentUser, $owner, $accessLevel, $this->ownerTreeProvider, $this->currentOrg)
            ->willReturn(false);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testInvalidNewEntityWithOrganizationOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('ORGANIZATION', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = $this->createOrganization(123);
        $this->testEntity->setOwner($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->getUserOrganizationIdsExpectation([2, 3]);

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testValidNewEntityWithNewUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = new User();
        $owner->setOrganization($this->currentOrg);
        $this->testEntity->setOwner($owner);
        $this->testEntity->setOrganization($this->currentOrg);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::exactly(2))
            ->method('getFieldValue')
            ->willReturnMap([
                [$this->testEntity, $ownershipMetadata->getOwnerFieldName(), $owner],
                [
                    $this->testEntity,
                    $ownershipMetadata->getOrganizationFieldName(),
                    $this->testEntity->getOrganization()
                ]
            ]);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidNewEntityWithNewBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = new BusinessUnit();
        $owner->setOrganization($this->currentOrg);
        $this->testEntity->setOwner($owner);
        $this->testEntity->setOrganization($this->currentOrg);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::exactly(2))
            ->method('getFieldValue')
            ->willReturnMap([
                [$this->testEntity, $ownershipMetadata->getOwnerFieldName(), $owner],
                [
                    $this->testEntity,
                    $ownershipMetadata->getOrganizationFieldName(),
                    $this->testEntity->getOrganization()
                ]
            ]);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::never())
            ->method('canBusinessUnitBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testValidNewEntityWithNewOrganizationOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('ORGANIZATION', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = new Organization();
        $this->testEntity->setOwner($owner);
        $this->testEntity->setOrganization($owner);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::once())
            ->method('getFieldValue')
            ->with($this->testEntity, $ownershipMetadata->getOwnerFieldName())
            ->willReturn($owner);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->assertNoViolation();
    }

    public function testInvalidNewEntityWithNewUserOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('USER', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = new User();
        $owner->setOrganization(new Organization());
        $this->testEntity->setOwner($owner);
        $this->testEntity->setOrganization($this->currentOrg);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::exactly(2))
            ->method('getFieldValue')
            ->willReturnMap([
                [$this->testEntity, $ownershipMetadata->getOwnerFieldName(), $owner],
                [
                    $this->testEntity,
                    $ownershipMetadata->getOrganizationFieldName(),
                    $this->testEntity->getOrganization()
                ]
            ]);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::never())
            ->method('canUserBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    public function testInvalidNewEntityWithNewBusinessUnitOwner()
    {
        $ownershipMetadata = new OwnershipMetadata('BUSINESS_UNIT', 'owner', 'owner', 'organization', 'organization');
        $entityMetadata = $this->createMock(ClassMetadata::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $accessLevel = AccessLevel::DEEP_LEVEL;

        $owner = new BusinessUnit();
        $owner->setOrganization(new Organization());
        $this->testEntity->setOwner($owner);
        $this->testEntity->setOrganization($this->currentOrg);

        $this->doctrine->expects(self::once())
            ->method('getManagerForClass')
            ->with(Entity::class)
            ->willReturn($em);
        $em->expects(self::once())
            ->method('getClassMetadata')
            ->willReturn($entityMetadata);
        $em->expects(self::once())
            ->method('getUnitOfWork')
            ->willReturn($uow);
        $this->ownershipMetadataProvider->expects(self::once())
            ->method('getMetadata')
            ->with(Entity::class)
            ->willReturn($ownershipMetadata);

        $entityMetadata->expects(self::exactly(2))
            ->method('getFieldValue')
            ->willReturnMap([
                [$this->testEntity, $ownershipMetadata->getOwnerFieldName(), $owner],
                [
                    $this->testEntity,
                    $ownershipMetadata->getOrganizationFieldName(),
                    $this->testEntity->getOrganization()
                ]
            ]);
        $uow->expects(self::once())
            ->method('getOriginalEntityData')
            ->with($this->testEntity)
            ->willReturn([$ownershipMetadata->getOwnerFieldName() => null]);

        $this->addOneShotIsGrantedObserverExpectation($accessLevel);
        $this->authorizationChecker->expects(self::once())
            ->method('isGranted')
            ->with('CREATE', 'entity:' . Entity::class)
            ->willReturn(true);
        $entityMetadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($this->testEntity)
            ->willReturn([]);
        $this->businessUnitManager->expects(self::never())
            ->method('canBusinessUnitBeSetAsOwner');

        $this->validator->validate($this->testEntity, $this->constraint);
        $this->buildViolation($this->constraint->message)
            ->atPath('owner')
            ->setParameters(['{{ owner }}' => 'owner'])
            ->assertRaised();
    }

    /**
     * @param int $accessLevel
     */
    private function addOneShotIsGrantedObserverExpectation($accessLevel)
    {
        $this->aclVoter->expects(self::once())
            ->method('addOneShotIsGrantedObserver')
            ->willReturnCallback(function (OneShotIsGrantedObserver $observer) use ($accessLevel) {
                $observer->setAccessLevel($accessLevel);
            });
    }

    private function getUserOrganizationIdsExpectation(array $organizationIds)
    {
        $ownerTree = $this->createMock(OwnerTreeInterface::class);
        $ownerTree->expects(self::once())
            ->method('getUserOrganizationIds')
            ->willReturn($organizationIds);
        $this->ownerTreeProvider->expects(self::once())
            ->method('getTree')
            ->willReturn($ownerTree);
    }
}
