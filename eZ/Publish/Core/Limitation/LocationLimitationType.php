<?php
/**
 * File containing the eZ\Publish\API\Repository\Values\User\Limitation\LocationLimitation class.
 *
 * @copyright Copyright (C) 1999-2013 eZ Systems AS. All rights reserved.
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License v2
 * @version //autogentag//
 */

namespace eZ\Publish\Core\Limitation;

use eZ\Publish\API\Repository\Values\ValueObject;
use eZ\Publish\API\Repository\Values\User\User as APIUser;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\ContentInfo;
use eZ\Publish\API\Repository\Values\Content\ContentCreateStruct;
use eZ\Publish\API\Repository\Values\Content\VersionInfo;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationCreateStruct;
use eZ\Publish\Core\Base\Exceptions\InvalidArgumentException;
use eZ\Publish\API\Repository\Values\User\Limitation\LocationLimitation as APILocationLimitation;
use eZ\Publish\API\Repository\Values\User\Limitation as APILimitationValue;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\SPI\Limitation\Type as SPILimitationTypeInterface;
use eZ\Publish\SPI\Persistence\Handler as SPIPersistenceHandler;
use eZ\Publish\SPI\Persistence\Content\Location as SPILocation;

/**
 * LocationLimitation is a Content limitation
 */
class LocationLimitationType implements SPILimitationTypeInterface
{
    /**
     * @var \eZ\Publish\SPI\Persistence\Handler
     */
    protected $persistence;

    /**
     * @param \eZ\Publish\SPI\Persistence\Handler $persistence
     */
    public function __construct( SPIPersistenceHandler $persistence )
    {
        $this->persistence = $persistence;
    }

    /**
     * Accepts a Limitation value
     *
     * Makes sure LimitationValue object is of correct type and that ->limitationValues
     * is valid according to valueSchema().
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $limitationValue
     *
     * @return boolean
     */
    public function acceptValue( APILimitationValue $limitationValue )
    {
        throw new \eZ\Publish\API\Repository\Exceptions\NotImplementedException( __METHOD__ );
    }

    /**
     * Create the Limitation Value
     *
     * @param mixed[] $limitationValues
     *
     * @return \eZ\Publish\API\Repository\Values\User\Limitation
     */
    public function buildValue( array $limitationValues )
    {
        return new APILocationLimitation( array( 'limitationValues' => $limitationValues ) );
    }

    /**
     * Evaluate permission against content & target(placement/parent/assignment)
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If any of the arguments are invalid
     *         Example: If LimitationValue is instance of ContentTypeLimitationValue, and Type is SectionLimitationType.
     * @throws \eZ\Publish\API\Repository\Exceptions\BadStateException If value of the LimitationValue is unsupported
     *         Example if OwnerLimitationValue->limitationValues[0] is not one of: [ 1,  2 ]
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param \eZ\Publish\API\Repository\Values\User\User $currentUser
     * @param \eZ\Publish\API\Repository\Values\ValueObject $object
     * @param \eZ\Publish\API\Repository\Values\ValueObject[] $targets An array of location, parent or "assignment" value objects
     *
     * @return boolean
     */
    public function evaluate( APILimitationValue $value, APIUser $currentUser, ValueObject $object, array $targets = array() )
    {
        if ( !$value instanceof APILocationLimitation )
        {
            throw new InvalidArgumentException( '$value', 'Must be of type: APILocationLimitation' );
        }

        if ( $object instanceof ContentCreateStruct )
        {
            return $this->evaluateForContentCreateStruct( $value, $targets );
        }
        else if ( $object instanceof Content )
        {
            $object = $object->getVersionInfo()->getContentInfo();
        }
        else if ( $object instanceof VersionInfo )
        {
            $object = $object->getContentInfo();
        }
        else if ( !$object instanceof ContentInfo )
        {
            throw new InvalidArgumentException(
                '$object',
                'Must be of type: ContentCreateStruct, Content, VersionInfo or ContentInfo'
            );
        }

        // Check all locations if no specific placement was provided
        if ( empty( $targets ) )
        {
            $targets = $this->persistence->locationHandler()->loadLocationsByContent( $object->id );
        }

        foreach ( $targets as $target )
        {
            if ( !$target instanceof Location && !$target instanceof SPILocation )
            {
                throw new InvalidArgumentException( '$targets', 'Must contain objects of type: Location' );
            }

            // Single match is sufficient
            if ( in_array( $target->id, $value->limitationValues ) )
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate permissions for ContentCreateStruct against LocationCreateStruct placements.
     *
     * @throws \eZ\Publish\API\Repository\Exceptions\InvalidArgumentException If $targets does not contain
     *         objects of type LocationCreateStruct
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param array $targets
     *
     * @return bool
     */
    protected function evaluateForContentCreateStruct( APILimitationValue $value, array $targets )
    {
        // If targets is empty return false as user does not have access
        // to content w/o location with this limitation
        if ( empty( $targets ) )
        {
            return false;
        }

        foreach ( $targets as $target )
        {
            if ( !$target instanceof LocationCreateStruct )
            {
                throw new InvalidArgumentException(
                    '$targets',
                    'If $object is ContentCreateStruct must contain objects of type: LocationCreateStruct'
                );
            }

            // For ContentCreateStruct all placements must match
            if ( !in_array( $target->parentLocationId, $value->limitationValues ) )
            {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns Criterion for use in find() query
     *
     * @param \eZ\Publish\API\Repository\Values\User\Limitation $value
     * @param \eZ\Publish\API\Repository\Values\User\User $currentUser
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Query\CriterionInterface
     */
    public function getCriterion( APILimitationValue $value, APIUser $currentUser )
    {
        if ( empty( $value->limitationValues )  )// no limitation values
            throw new \RuntimeException( "\$value->limitationValues is empty, it should not have been stored in the first place" );

        if ( !isset( $value->limitationValues[1] ) )// 1 limitation value: EQ operation
            return new Criterion\LocationId( $value->limitationValues[0] );

        // several limitation values: IN operation
        return new Criterion\LocationId( $value->limitationValues );
    }

    /**
     * Returns info on valid $limitationValues
     *
     * @return mixed[]|int In case of array, a hash with key as valid limitations value and value as human readable name
     *                     of that option, in case of int on of VALUE_SCHEMA_ constants.
     */
    public function valueSchema()
    {
        return self::VALUE_SCHEMA_LOCATION_ID;
    }
}
